<?php

namespace Plugin\LongosBotShield\Services;

use App\Saas\Support\TenantDeferred;
use Illuminate\Support\Facades\Cache;

class NetworkIntelligenceClient
{
    public function __construct(
        private readonly IpInfoClient $ipInfo,
        private readonly BotShieldSettings $settings,
    ) {}

    public function lookup(string $ip): array
    {
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
        return $this->ipInfo->available()
            ? $this->ipInfo->lookup($ip)
            : $this->ipInfo->lookupCached($ip);
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
