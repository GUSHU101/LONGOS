<?php

namespace App\Saas;

use App\Saas\Contracts\DnsRouteResolver;
use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\Support\SensitiveValueRedactor;
use App\Services\BundledPluginSynchronizer;
use Beike\Services\CloudflareIpRangeService;
use Illuminate\Support\Facades\DB;
use Throwable;

class SiteHealthChecker
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly SiteAuditLogger $audit,
        private readonly ?DnsRouteResolver $routingDns = null,
    ) {}

    /** @return array<string, mixed> */
    public function check(Site $site, bool $checkDns = true): array
    {
        $database = [
            'ok'               => false,
            'latency_ms'       => null,
            'missing_tables'   => [],
            'missing_settings' => [],
            'plugin_issues'    => [],
            'root_admin_ready' => false,
            'error'            => null,
        ];

        try {
            $startedAt = microtime(true);
            $database  = $this->tenants->run($site, function (): array {
                DB::connection()->getPdo();
                $schema   = DB::connection()->getSchemaBuilder();
                $required = [
                    'migrations',
                    'settings',
                    'admin_users',
                    'permissions',
                    'roles',
                    'plugins',
                    'categories',
                    'customers',
                    'carts',
                    'products',
                    'orders',
                ];
                if (config('cache.default')                       === 'database'
                    && config('cache.stores.database.connection') === null) {
                    $required[] = (string) config('cache.stores.database.table', 'cache');
                    $required[] = (string) config('cache.stores.database.lock_table', 'cache_locks');
                }
                $required        = array_values(array_unique($required));
                $missing         = array_values(array_filter($required, static fn (string $table): bool => ! $schema->hasTable($table)));
                $missingSettings = [];
                $pluginIssues    = [];
                $rootAdminReady  = false;
                if ($missing === []) {
                    $missingSettings = array_values(array_filter(
                        ['theme', 'menu_setting', 'design_setting', 'footer_setting'],
                        static fn (string $name): bool => ! DB::table('settings')
                            ->where('type', 'system')
                            ->where('space', 'base')
                            ->where('name', $name)
                            ->exists(),
                    ));
                    $rootAdminReady = DB::table('admin_users')
                        ->where('id', 1)
                        ->where('active', true)
                        ->exists();

                    $installedPlugins = DB::table('plugins')->pluck('code')->map(
                        static fn ($code): string => (string) $code,
                    )->all();
                    foreach (BundledPluginSynchronizer::packageCodes() as $code) {
                        if (! in_array($code, $installedPlugins, true)) {
                            $pluginIssues[] = "{$code}: 未登记";
                        }
                    }
                    foreach (BundledPluginSynchronizer::requiredTables() as $code => $tables) {
                        foreach ($tables as $table) {
                            if (! $schema->hasTable($table)) {
                                $pluginIssues[] = "{$code}: 缺少数据表 {$table}";
                            }
                        }
                    }
                }

                return [
                    'ok'               => $missing === [] && $missingSettings === [] && $pluginIssues === [] && $rootAdminReady,
                    'missing_tables'   => $missing,
                    'missing_settings' => $missingSettings,
                    'plugin_issues'    => $pluginIssues,
                    'root_admin_ready' => $rootAdminReady,
                ];
            });
            $database['latency_ms'] = round((microtime(true) - $startedAt) * 1000, 2);
            $database['error']      = null;
        } catch (Throwable $exception) {
            $database['error'] = SensitiveValueRedactor::redact($exception->getMessage());
        }

        $domains = [];
        foreach ($site->domains as $domain) {
            if ($domain->status !== Domain::STATUS_ACTIVE) {
                $domains[] = [
                    'host'     => $domain->host,
                    'status'   => $domain->status,
                    'ok'       => null,
                    'records'  => [],
                    'expected' => (array) config('saas.dns_targets', []),
                    'error'    => $domain->verification_error,
                ];

                continue;
            }

            $domains[] = $checkDns ? $this->checkDomain($domain) : [
                'host'     => $domain->host,
                'status'   => $domain->status,
                'ok'       => null,
                'records'  => [],
                'expected' => (array) config('saas.dns_targets', []),
                'error'    => null,
            ];
        }

        $dnsFailures  = count(array_filter($domains, static fn (array $domain): bool => $domain['ok'] === false));
        $primaryCount = $site->domains
            ->filter(static fn (Domain $domain): bool => $domain->status === Domain::STATUS_ACTIVE && $domain->is_primary)
            ->count();
        $domainInvariants = [
            'ok'                   => $primaryCount === 1,
            'active_primary_count' => $primaryCount,
            'error'                => $primaryCount === 1 ? null : '站点必须且只能有一个已启用的主域名。',
        ];
        $status = ! $database['ok'] || ! $domainInvariants['ok']
            ? 'unhealthy'
            : ($dnsFailures > 0 ? 'degraded' : 'healthy');

        $site->forceFill([
            'health_status'     => $status,
            'health_checked_at' => now(),
        ])->save();

        $result = [
            'site_id'           => $site->id,
            'code'              => $site->code,
            'status'            => $status,
            'checked_at'        => now()->toIso8601String(),
            'database'          => $database,
            'domains'           => $domains,
            'domain_invariants' => $domainInvariants,
        ];
        $this->audit->record($site, 'health.check', $status, null, $result);

        return $result;
    }

    /** @return array{host: string, status: string, ok: bool, records: array<int, string>, expected: array<int, string>, error: string|null} */
    private function checkDomain(Domain $domain): array
    {
        $expected = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtolower(rtrim(trim((string) $value), '.')),
            (array) config('saas.dns_targets', []),
        ))));
        if ($domain->host === 'localhost' || filter_var($domain->host, FILTER_VALIDATE_IP)) {
            $records = [$domain->host];
        } else {
            $resolver = $this->routingDns ?? app(DnsRouteResolver::class);
            $records  = $resolver->resolve($domain->host);
        }

        $expandedExpected = $expected;
        $resolver         = $this->routingDns ?? app(DnsRouteResolver::class);
        foreach ($expected as $target) {
            $expandedExpected = [...$expandedExpected, ...$resolver->resolve($target)];
        }
        $expandedExpected  = array_values(array_unique($expandedExpected));
        $recordAddresses   = array_values(array_filter($records, static fn (string $value): bool => filter_var($value, FILTER_VALIDATE_IP) !== false));
        $expectedAddresses = array_values(array_filter($expandedExpected, static fn (string $value): bool => filter_var($value, FILTER_VALIDATE_IP) !== false));
        $hasRecords        = $records !== [];
        $matchesTarget     = $expected === [] || ($recordAddresses !== [] && $expectedAddresses !== []
            ? array_diff($recordAddresses, $expectedAddresses) === []
            : array_intersect($records, $expandedExpected) !== []);
        if (! $matchesTarget
            && strtolower((string) config('longos.cloudflare.mode', 'off')) !== 'off'
            && $recordAddresses                                             !== []) {
            $cloudflare    = new CloudflareIpRangeService;
            $matchesTarget = array_reduce(
                $recordAddresses,
                static fn (bool $valid, string $address): bool => $valid && $cloudflare->contains($address),
                true,
            );
        }
        $error         = ! $hasRecords
            ? '未查询到 A、AAAA 或 CNAME 记录。'
            : (! $matchesTarget ? '当前 DNS 记录未指向 SAAS_DNS_TARGETS。' : null);
        $result = [
            'host'     => $domain->host,
            'status'   => $domain->status,
            'ok'       => $hasRecords && $matchesTarget,
            'records'  => $records,
            'expected' => $expected,
            'error'    => $error,
        ];

        $domain->forceFill([
            'last_checked_at'    => now(),
            'verification_error' => $result['error'],
        ])->save();

        return $result;
    }
}
