<?php

namespace Plugin\LongosBotShield\Services;

use App\Saas\Support\TenantDeferred;
use Illuminate\Support\Facades\Cache;

class NetworkIntelligenceClient
{
    public function __construct(
        private readonly IpInfoLocalDatabase $localDatabase,
        private readonly IpInfoClient $ipInfo,
        private readonly BotShieldSettings $settings,
    ) {}

    public function lookup(string $ip): array
    {
        $local         = $this->localDatabase->lookup($ip);
        $localFallback = null;
        if (($local['status'] ?? '') === 'ok') {
            $this->localDatabase->scheduleSyncIfStale($this->settings->ipinfoToken());
            if (! ($local['identity_incomplete'] ?? false)) {
                return $local;
            }

            // Keep the exact local ASN as the final fallback so a failed
            // enrichment request cannot remove a deny-list match.
            $localFallback = $local;
        } else {
            $this->localDatabase->scheduleSyncIfStale($this->settings->ipinfoToken());
        }

        $result = $this->ipInfo->lookupCached($ip);
        if (($result['status'] ?? '') === 'ok') {
            if (($result['cache_stale'] ?? false) === true) {
                $this->scheduleRefresh($ip);
            }

            return $result;
        }

        // A first-seen IP gets one bounded synchronous lookup so ASN-based
        // shielding works on its first request. IPInfo has no inline retry and
        // a strict 0.8-second maximum request budget.
        $refreshKey = $this->refreshKey($ip);
        if (! Cache::add($refreshKey, true, now()->addSeconds(15))) {
            return $localFallback ?? $result;
        }

        try {
            $refreshed = $localFallback !== null
                ? ($this->ipInfo->available()
                    ? $this->ipInfo->lookup($ip)
                    : $this->ipInfo->lookupCached($ip))
                : $this->refresh($ip);

            return ($refreshed['status'] ?? '') === 'ok' ? $refreshed : ($localFallback ?? $refreshed);
        } finally {
            Cache::forget($refreshKey);
        }
    }

    public function refresh(string $ip): array
    {
        $local = $this->localDatabase->lookup($ip);
        if (($local['status'] ?? '') === 'ok' && ! ($local['identity_incomplete'] ?? false)) {
            return $local;
        }

        $live = $this->ipInfo->available()
            ? $this->ipInfo->lookup($ip)
            : $this->ipInfo->lookupCached($ip);

        return ($live['status'] ?? '') === 'ok' ? $live : $local;
    }

    private function scheduleRefresh(string $ip): void
    {
        if (! $this->ipInfo->available()) {
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
}
