<?php

namespace App\Saas;

use App\Saas\Contracts\DnsRouteResolver;
use App\Saas\Contracts\DnsTxtResolver;
use App\Saas\Contracts\DomainTrafficProbe;
use App\Saas\Events\DomainVerified;
use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\Support\DnsTxtAnswer;
use App\Saas\Support\DomainName;
use App\Saas\Support\SensitiveValueRedactor;
use Beike\Services\CloudflareIpRangeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DomainVerificationService
{
    public function __construct(
        private readonly DnsTxtResolver $dns,
        private readonly DnsRouteResolver $routingDns,
        private readonly DomainTrafficProbe $trafficProbe,
        private readonly DomainConfigWriter $domainConfig,
        private readonly SiteAuditLogger $audit,
    ) {}

    public function request(Site $site, string $value): Domain
    {
        $this->ensureEnabled();
        $parsed = DomainName::parse($value, (string) config('saas.default_scheme', 'https'));
        // Tenant-managed domains are always promoted to HTTPS. The ownership
        // TXT ownership is independent of HTTP. Caddy obtains and probes the
        // public certificate while the domain is fail-closed in ACTIVATING.
        $parsed['scheme'] = 'https';
        if ($parsed['host'] === 'localhost' || filter_var($parsed['host'], FILTER_VALIDATE_IP)) {
            throw new RuntimeException('自定义域名必须是可通过 DNS 验证的完整域名。');
        }
        if ($this->isControlDomain($parsed['host'])) {
            throw new RuntimeException('控制站域名不能绑定为租户自定义域名。');
        }
        if ($this->isPlatformDomain($parsed['host'])) {
            throw new RuntimeException('平台子域名命名空间不能作为租户自定义域名绑定。');
        }

        $domain = DB::connection('saas')->transaction(function () use ($site, $parsed): Domain {
            $lockedSite = Site::query()->lockForUpdate()->findOrFail($site->getKey());
            if ($lockedSite->status !== Site::STATUS_ACTIVE) {
                throw new RuntimeException('只有已上线站点可以申请自定义域名。');
            }

            $existing = Domain::query()->where('host', $parsed['host'])->lockForUpdate()->first();
            if ($existing && $existing->site_id !== $lockedSite->id) {
                throw new RuntimeException('该域名当前不可用。');
            }
            if ($existing?->status === Domain::STATUS_ACTIVE) {
                return $existing;
            }
            if ($existing?->status === Domain::STATUS_PENDING && $existing->verification_expires_at?->isFuture()) {
                return $existing;
            }

            $maxDomains  = max(1, (int) config('saas.custom_domains.max_per_site', 10));
            $customCount = $lockedSite->domains()->where('origin', Domain::ORIGIN_TENANT)->count();
            if (! $existing && $customCount >= $maxDomains) {
                throw new RuntimeException("每个站点最多绑定 {$maxDomains} 个自定义域名。");
            }

            $attributes = [
                'scheme'                  => $parsed['scheme'],
                'is_primary'              => false,
                'routing_behavior'        => Domain::ROUTING_REDIRECT,
                'status'                  => Domain::STATUS_PENDING,
                'origin'                  => Domain::ORIGIN_TENANT,
                'verification_method'     => Domain::VERIFICATION_DNS_TXT,
                'verification_token'      => $this->newToken(),
                'verification_expires_at' => now()->addHours(max(1, (int) config('saas.custom_domains.verification_ttl_hours', 72))),
                'verification_attempts'   => 0,
                'verified_at'             => null,
                'last_checked_at'         => null,
                'verification_error'      => null,
            ];

            if ($existing) {
                $existing->forceFill($attributes)->save();

                return $existing;
            }

            return $lockedSite->domains()->create(['host' => $parsed['host'], ...$attributes]);
        });

        $this->audit->record($site, 'domain.verification.requested', 'pending', null, ['host' => $domain->host]);

        return $domain->refresh();
    }

    public function verify(Site $site, Domain $domain): Domain
    {
        return $this->domainConfig->synchronized(
            fn (): Domain => $this->verifySynchronized($site, $domain)
        );
    }

    private function verifySynchronized(Site $site, Domain $domain): Domain
    {
        $this->ensureEnabled();
        if ($domain->site_id !== $site->id || $domain->origin !== Domain::ORIGIN_TENANT) {
            throw new RuntimeException('自定义域名不属于当前站点。');
        }
        if ($domain->status === Domain::STATUS_ACTIVE && $domain->isRoutable()) {
            return $domain;
        }

        $republishing = in_array($domain->status, [Domain::STATUS_PENDING, Domain::STATUS_ACTIVE], true)
            && $domain->origin       === Domain::ORIGIN_TENANT
            && $domain->published_at === null
            && $domain->verified_at !== null
            && $domain->verification_token === null;
        if (! $republishing && ($domain->status !== Domain::STATUS_PENDING || ! is_string($domain->verification_token))) {
            throw new RuntimeException('该域名当前不处于待验证状态。');
        }
        if (! $republishing && $domain->verification_expires_at?->isPast()) {
            throw new RuntimeException('域名验证记录已过期，请重新申请以生成新的 TXT 记录。');
        }

        if (! $republishing) {
            $recordName = $domain->verification_record_name;
            $answers    = $this->dns->resolve($recordName);
            $expected   = $domain->verification_record_value;
            $verified   = is_string($expected) && DnsTxtAnswer::contains($answers, $expected);

            if (! $verified) {
                $message = $answers === []
                    ? "未查询到 {$recordName} 的 TXT 记录。"
                    : 'TXT 记录存在，但验证值不匹配。';
                Domain::query()->whereKey($domain->getKey())->where('status', Domain::STATUS_PENDING)->update([
                    'last_checked_at'       => now(),
                    'verification_attempts' => DB::raw('verification_attempts + 1'),
                    'verification_error'    => $message,
                ]);
                $domain->refresh();
                $this->audit->record($site, 'domain.verification.checked', 'failed', $message, ['host' => $domain->host]);

                throw new RuntimeException($message);
            }
        }

        $this->assertTrafficDns($site, $domain);

        $token          = $domain->verification_token;
        $previousStatus = $domain->status;
        $activated      = DB::connection('saas')->transaction(function () use ($site, $domain, $token, $republishing): Domain {
            Site::query()->lockForUpdate()->findOrFail($site->getKey());
            $locked       = Domain::query()->lockForUpdate()->findOrFail($domain->getKey());
            $validPending = ! $republishing
                && $locked->status === Domain::STATUS_PENDING
                && hash_equals((string) $locked->verification_token, (string) $token);
            $validRepublish = $republishing
                && in_array($locked->status, [Domain::STATUS_PENDING, Domain::STATUS_ACTIVE], true)
                && $locked->origin       === Domain::ORIGIN_TENANT
                && $locked->published_at === null
                && $locked->verified_at !== null
                && $locked->verification_token === null;
            if ($locked->site_id !== $site->id || (! $validPending && ! $validRepublish)) {
                throw new RuntimeException('域名验证状态已发生变化，请刷新后重试。');
            }

            $locked->forceFill([
                // ACTIVATING is published to the web server while remaining
                // invisible to TenantFinder. It becomes routable only after
                // the complete configuration sync succeeds.
                'status'                => Domain::STATUS_ACTIVATING,
                'verified_at'           => $locked->verified_at ?: now(),
                'last_checked_at'       => now(),
                'verification_attempts' => $locked->verification_attempts + 1,
                'verification_error'    => null,
                'publication_error'     => null,
            ])->save();

            return $locked;
        });

        $published      = false;
        $probeAttempted = false;

        try {
            $this->domainConfig->sync();
            $published = true;

            // This request deliberately happens while the domain is still
            // ACTIVATING. In Caddy mode the TLS handshake invokes the ask
            // endpoint, obtains the certificate, then reaches the signed
            // probe. The domain is never shown as enabled before that entire
            // public HTTPS path is proven to work.
            $probeAttempted = true;
            $probe          = $this->trafficProbe->probe($activated->refresh());
            if (! $probe['ok']) {
                $caddy = strtolower((string) config('saas.tls.driver', 'caddy')) === 'caddy';

                throw new RuntimeException(
                    $caddy
                        ? 'DNS 已验证，但 Caddy 未能完成按需证书签发或 HTTPS 回源。请确认公网 80/443 已直达 Caddy；使用 Cloudflare 时请启用代理并设置 Full (strict)。'
                            . ($probe['error'] ? ' ' . $probe['error'] : '')
                        : '域名所有权和 DNS 指向已验证，但外部 HTTPS 入口尚未真正接收该 Host，或有效证书尚未部署。'
                            . ($probe['error'] ? ' ' . $probe['error'] : '')
                );
            }

            $completed = Domain::query()
                ->whereKey($activated->getKey())
                ->where('status', Domain::STATUS_ACTIVATING)
                ->update([
                    'status'                   => Domain::STATUS_ACTIVE,
                    'verified_at'              => $activated->verified_at ?: now(),
                    'published_at'             => now(),
                    'last_probe_at'            => now(),
                    'publication_error'        => null,
                    'verification_token'       => null,
                    'verification_expires_at'  => null,
                    'verification_error'       => null,
                    'updated_at'               => now(),
                ]);
            if ($completed !== 1) {
                throw new RuntimeException('域名配置已发布，但验证状态已变更。');
            }
            $activated->refresh();
        } catch (Throwable $exception) {
            $safeMessage = SensitiveValueRedactor::redact($exception->getMessage());

            try {
                DB::connection('saas')->transaction(function () use ($activated, $token, $safeMessage, $previousStatus, $probeAttempted): void {
                    $locked      = Domain::query()->lockForUpdate()->findOrFail($activated->getKey());
                    $sameAttempt = $token             === null
                        ? $locked->verification_token === null
                        : hash_equals((string) $locked->verification_token, (string) $token);
                    if ($locked->status === Domain::STATUS_ACTIVATING && $sameAttempt) {
                        $locked->forceFill([
                            'status'             => $previousStatus,
                            'published_at'       => null,
                            'last_probe_at'      => $probeAttempted ? now() : $locked->last_probe_at,
                            'publication_error'  => $safeMessage,
                            'verification_error' => '域名发布未完成：' . $safeMessage,
                        ])->save();
                    }
                });
            } catch (Throwable $rollbackException) {
                report($rollbackException);
            }
            if ($published) {
                try {
                    $this->domainConfig->sync();
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            $this->audit->record($site, 'domain.verification.activated', 'failed', $safeMessage, ['host' => $activated->host]);

            throw new RuntimeException('域名未启用：' . $safeMessage);
        }

        $this->audit->record($site, 'domain.verification.activated', 'success', null, ['host' => $activated->host]);

        try {
            DomainVerified::dispatch($activated->refresh());
        } catch (Throwable $exception) {
            // Certificate automation and other extension listeners must not
            // turn a completed ownership activation into a false API failure.
            report($exception);
            $this->audit->record($site, 'domain.verified.listener', 'failed', $exception->getMessage(), ['host' => $activated->host]);
        }

        return $activated;
    }

    private function assertTrafficDns(Site $site, Domain $domain): void
    {
        $expected = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtolower(rtrim(trim((string) $value), '.')),
            (array) config('saas.dns_targets', []),
        ))));
        if ($expected === []) {
            $message = '平台未配置 SAAS_DNS_TARGETS，无法证明自有域名已指向当前服务器。';
            $this->recordRoutingFailure($site, $domain, $message);

            throw new RuntimeException($message);
        }

        $records          = $this->routingDns->resolve($domain->host);
        $expandedExpected = $expected;
        foreach ($expected as $target) {
            $expandedExpected = [...$expandedExpected, ...$this->routingDns->resolve($target)];
        }
        $expandedExpected = array_values(array_unique(array_map(
            static fn (string $value): string => strtolower(rtrim($value, '.')),
            $expandedExpected,
        )));

        if ($records === []) {
            $message = "未查询到 {$domain->host} 的 A、AAAA 或 CNAME 记录。";
            $this->recordRoutingFailure($site, $domain, $message);

            throw new RuntimeException($message);
        }
        $recordAddresses   = array_values(array_filter($records, static fn (string $value): bool => filter_var($value, FILTER_VALIDATE_IP) !== false));
        $expectedAddresses = array_values(array_filter($expandedExpected, static fn (string $value): bool => filter_var($value, FILTER_VALIDATE_IP) !== false));
        $matchesTarget     = $recordAddresses !== [] && $expectedAddresses !== []
            ? array_diff($recordAddresses, $expectedAddresses) === []
            : array_intersect($records, $expandedExpected) !== [];

        // Proxied Cloudflare records intentionally resolve to Cloudflare
        // anycast addresses rather than the LONGOS origin. TXT ownership plus
        // the mandatory signed HTTPS probe below remains the decisive proof
        // that the hostname reaches this installation, so accepting only
        // official Cloudflare ranges here does not weaken tenant isolation.
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
        if (! $matchesTarget) {
            $message = "{$domain->host} 当前解析为 " . implode(', ', $records)
                . '，但必须指向 ' . implode(', ', $expected) . '。';
            $this->recordRoutingFailure($site, $domain, $message);

            throw new RuntimeException($message);
        }
    }

    private function recordRoutingFailure(Site $site, Domain $domain, string $message): void
    {
        Domain::query()->whereKey($domain->getKey())->update([
            'last_checked_at'    => now(),
            'verification_error' => $message,
        ]);
        $domain->refresh();
        $this->audit->record($site, 'domain.routing.checked', 'failed', $message, ['host' => $domain->host]);
    }

    public function rotate(Site $site, Domain $domain): Domain
    {
        $rotated = DB::connection('saas')->transaction(function () use ($site, $domain): Domain {
            Site::query()->lockForUpdate()->findOrFail($site->getKey());
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->getKey());
            if ($locked->site_id !== $site->id || $locked->origin !== Domain::ORIGIN_TENANT || $locked->status === Domain::STATUS_ACTIVE) {
                throw new RuntimeException('仅可刷新当前站点尚未激活的自定义域名验证记录。');
            }

            $locked->forceFill([
                'status'                  => Domain::STATUS_PENDING,
                'verification_token'      => $this->newToken(),
                'verification_expires_at' => now()->addHours(max(1, (int) config('saas.custom_domains.verification_ttl_hours', 72))),
                'verification_attempts'   => 0,
                'verified_at'             => null,
                'last_checked_at'         => null,
                'verification_error'      => null,
            ])->save();

            return $locked;
        });
        $this->audit->record($site, 'domain.verification.rotated', 'pending', null, ['host' => $rotated->host]);

        return $rotated;
    }

    /** @return array{name: string|null, type: string, value: string|null, expires_at: string|null} */
    public function instructions(Domain $domain): array
    {
        return [
            'name'       => $domain->verification_record_name,
            'type'       => 'TXT',
            'value'      => $domain->verification_record_value,
            'expires_at' => $domain->verification_expires_at?->toIso8601String(),
        ];
    }

    /**
     * Decide whether the control plane may publish a host as a platform
     * subdomain. Every other public hostname must use TXT ownership
     * verification and the HTTPS traffic probe before it becomes routable.
     */
    public function isPlatformManagedHost(string $value): bool
    {
        try {
            $parsed = DomainName::parse($value, (string) config('saas.default_scheme', 'https'));
            $base   = DomainName::normalize((string) config('saas.base_domain'));

            // The base domain itself and every control-plane hostname remain
            // reserved. Only a real child hostname can be provisioned as a
            // tenant's platform-managed subdomain.
            return $parsed['host'] !== $base
                && ! $this->isControlDomain($parsed['host'])
                && $this->isPlatformDomain($parsed['host']);
        } catch (Throwable) {
            return false;
        }
    }

    private function newToken(): string
    {
        return Str::lower(Str::random(48));
    }

    private function ensureEnabled(): void
    {
        if (! config('saas.enabled') || ! config('saas.custom_domains.enabled', true)) {
            throw new RuntimeException('租户自定义域名功能未启用。');
        }
    }

    private function isControlDomain(string $host): bool
    {
        foreach ((array) config('saas.control_domains', []) as $controlDomain) {
            try {
                $normalized = DomainName::normalize((string) $controlDomain);
                // Control domains are exact HTTP hosts, not DNS namespaces.
                // A common single-zone deployment intentionally uses the
                // apex for the control site and its children for tenants.
                if ($normalized === $host) {
                    return true;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return false;
    }

    private function isPlatformDomain(string $host): bool
    {
        $configured = trim((string) config('saas.base_domain'));
        if ($configured === '') {
            return false;
        }

        try {
            $baseDomain = DomainName::normalize($configured);
        } catch (Throwable) {
            return false;
        }

        return $host === $baseDomain || str_ends_with($host, '.' . $baseDomain);
    }
}
