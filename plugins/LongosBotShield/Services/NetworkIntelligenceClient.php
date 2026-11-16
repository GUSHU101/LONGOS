<?php

namespace Plugin\LongosBotShield\Services;

use App\Saas\Support\TenantDeferred;
use Illuminate\Support\Facades\Cache;

class NetworkIntelligenceClient
{
    public function __construct(
        private readonly IpInfoClient $ipInfo,
        private readonly CloudflareRadarClient $cloudflareRadar,
        private readonly BotShieldSettings $settings,
    ) {}

    public function lookup(string $ip): array
    {
        $primary  = $this->ipInfo->lookupCached($ip);
        $fallback = $this->cloudflareRadar->lookupCached($ip);
        $result   = $this->resolve($primary, $fallback);

        $primaryUsable  = ($primary['status'] ?? '')  === 'ok' && (string) ($primary['asn'] ?? '') !== '';
        $fallbackUsable = ($fallback['status'] ?? '') === 'ok';
        if ($primaryUsable || $fallbackUsable) {
            if (($primary['cache_stale'] ?? false) === true
                || ! $primaryUsable
                || ($fallback['cache_stale'] ?? false) === true) {
                $this->scheduleRefresh($ip);
            }

            return $result;
        }

        // A first-seen IP gets one bounded synchronous lookup so ASN-based
        // shielding works on its first request. The provider clients have no
        // inline retries and share a strict 0.45s + 0.30s maximum budget.
        $refreshKey = $this->refreshKey($ip);
        if (! Cache::add($refreshKey, true, now()->addSeconds(15))) {
            return $result;
        }

        try {
            return $this->refresh($ip);
        } finally {
            Cache::forget($refreshKey);
        }
    }

    public function refresh(string $ip): array
    {
        $primary = $this->ipInfo->available()
            ? $this->ipInfo->lookup($ip)
            : $this->ipInfo->lookupCached($ip);
        if (! $this->cloudflareRadar->available()) {
            return $primary;
        }

        $primaryNeedsFallback = ($primary['status'] ?? '') === 'unavailable'
            || (($primary['status'] ?? '') === 'ok' && (string) ($primary['asn'] ?? '') === '');
        if (! $primaryNeedsFallback) {
            return $primary;
        }

        return $this->resolve($primary, $this->cloudflareRadar->lookup($ip));
    }

    private function resolve(array $primary, array $fallback): array
    {
        if (($fallback['status'] ?? '') !== 'ok') {
            return $primary;
        }

        $primaryUnavailable = ($primary['status'] ?? '') === 'unavailable';
        $primaryIncomplete  = ($primary['status'] ?? '') === 'ok'
            && (string) ($primary['asn'] ?? '')          === '';
        if (! $primaryUnavailable && ! $primaryIncomplete) {
            return $primary;
        }

        if ($primaryUnavailable) {
            return $fallback;
        }

        return $this->merge($primary, $fallback);
    }

    private function scheduleRefresh(string $ip): void
    {
        if (! $this->ipInfo->available() && ! $this->cloudflareRadar->available()) {
            return;
        }

        $key = $this->refreshKey($ip);
        if (! Cache::add($key, true, now()->addSeconds(15))) {
            return;
        }

        TenantDeferred::terminating(static function () use ($ip, $key): void {
            try {
                app(self::class)->refresh($ip);
            } finally {
                Cache::forget($key);
            }
        });
    }

    private function refreshKey(string $ip): string
    {
        return 'longos:bot-shield:network-refresh-scheduled:' . hash('sha256', implode('|', [
            $this->settings->tenantKey(),
            $this->settings->ipHash($ip),
        ]));
    }

    private function merge(array $primary, array $fallback): array
    {
        $result                          = $primary;
        $result['provider']              = 'ipinfo+cloudflare-radar';

        foreach (['asn', 'name', 'domain', 'type', 'company_name', 'company_domain', 'company_type', 'privacy_name'] as $key) {
            if (trim((string) ($result[$key] ?? '')) === '') {
                $result[$key] = (string) ($fallback[$key] ?? '');
            }
        }

        return $result;
    }
}
