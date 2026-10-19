<?php

use App\Saas\Support\DomainName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDefaultConnection() === 'tenant'
            || ! Schema::hasTable('saas_sites')
            || ! Schema::hasTable('saas_domains')) {
            return;
        }

        $configuredBase = trim((string) config('saas.base_domain', ''));
        if ($configuredBase === '') {
            // Without the platform namespace there is no safe way to
            // distinguish managed subdomains from customer-owned hosts.
            return;
        }

        try {
            $baseDomain = DomainName::normalize($configuredBase);
        } catch (Throwable) {
            return;
        }

        $controlHosts = [];
        foreach ((array) config('saas.control_domains', []) as $controlDomain) {
            try {
                $controlHosts[] = DomainName::normalize((string) $controlDomain);
            } catch (Throwable) {
                continue;
            }
        }

        DB::transaction(function () use ($baseDomain, $controlHosts): void {
            $sites = DB::table('saas_sites')->orderBy('id')->get(['id', 'code', 'meta']);
            foreach ($sites as $site) {
                $meta = json_decode((string) ($site->meta ?? ''), true);
                if (is_array($meta) && (bool) ($meta['control_site'] ?? false)) {
                    continue;
                }

                $domains = DB::table('saas_domains')
                    ->where('site_id', $site->id)
                    ->orderBy('id')
                    ->get();
                $misclassified = $domains->filter(fn ($domain): bool => (string) $domain->origin === 'platform'
                    && in_array((string) $domain->status, ['active', 'activating'], true)
                    && ! $this->isManagedHost((string) $domain->host, $baseDomain, $controlHosts)
                );
                if ($misclassified->isEmpty()) {
                    continue;
                }

                $now = now();
                foreach ($misclassified as $domain) {
                    DB::table('saas_domains')->where('id', $domain->id)->update([
                        'status'                   => 'pending',
                        'origin'                   => 'tenant',
                        'is_primary'               => false,
                        'routing_behavior'         => 'redirect',
                        'verification_method'      => 'dns_txt',
                        'verification_token'       => Str::lower(Str::random(48)),
                        'verification_expires_at'  => $now->copy()->addHours(max(1, (int) config('saas.custom_domains.verification_ttl_hours', 72))),
                        'verification_attempts'    => 0,
                        'verified_at'              => null,
                        'last_checked_at'          => null,
                        'verification_error'       => '该域名由旧版本直接发布，升级后必须重新完成所有权、流量 DNS 和 HTTPS 验证。',
                        'published_at'             => null,
                        'last_probe_at'            => null,
                        'publication_error'        => null,
                        'updated_at'               => $now,
                    ]);
                }

                $fallback = $domains->first(fn ($domain): bool => (string) $domain->origin === 'platform'
                    && (string) $domain->status                                            === 'active'
                    && $this->isManagedHost((string) $domain->host, $baseDomain, $controlHosts)
                );
                if (! $fallback) {
                    $candidate = strtolower(trim((string) $site->code)) . '.' . $baseDomain;
                    $owner     = DB::table('saas_domains')->where('host', $candidate)->first();
                    if (! $owner && $this->isManagedHost($candidate, $baseDomain, $controlHosts)) {
                        $fallbackId = DB::table('saas_domains')->insertGetId([
                            'site_id'                   => $site->id,
                            'host'                      => $candidate,
                            'scheme'                    => 'https',
                            'is_primary'                => true,
                            'routing_behavior'          => 'redirect',
                            'status'                    => 'active',
                            'origin'                    => 'platform',
                            'verification_method'       => null,
                            'verification_token'        => null,
                            'verification_expires_at'   => null,
                            'verification_attempts'     => 0,
                            'verified_at'               => null,
                            'last_checked_at'           => null,
                            'verification_error'        => null,
                            'published_at'              => $now,
                            'last_probe_at'             => null,
                            'publication_error'         => null,
                            'created_at'                => $now,
                            'updated_at'                => $now,
                        ]);
                        $fallback = (object) ['id' => $fallbackId];
                    }
                }

                if ($fallback) {
                    DB::table('saas_domains')->where('site_id', $site->id)->update([
                        'is_primary' => false,
                        'updated_at' => $now,
                    ]);
                    DB::table('saas_domains')->where('id', $fallback->id)->update([
                        'is_primary'       => true,
                        'routing_behavior' => 'redirect',
                        'updated_at'       => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Restoring unverified external domains as active would recreate the
        // ownership-verification bypass, so this data hardening is one-way.
    }

    /** @param array<int, string> $controlHosts */
    private function isManagedHost(string $host, string $baseDomain, array $controlHosts): bool
    {
        $host = strtolower(trim($host, '.'));
        if ($host === $baseDomain || ! str_ends_with($host, '.' . $baseDomain)) {
            return false;
        }

        foreach ($controlHosts as $controlHost) {
            if ($host === $controlHost) {
                return false;
            }
        }

        return true;
    }
};
