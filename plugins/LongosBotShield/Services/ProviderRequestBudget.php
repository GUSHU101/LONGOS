<?php

namespace Plugin\LongosBotShield\Services;

use Illuminate\Support\Facades\RateLimiter;

class ProviderRequestBudget
{
    public function __construct(private readonly BotShieldSettings $settings) {}

    /**
     * Bound external network-intelligence lookups per tenant. Cached lookups are
     * checked before this method, so normal repeat traffic consumes no budget.
     */
    public function consume(): bool
    {
        $key = 'longos:bot-shield:provider-budget:' . hash(
            'sha256',
            $this->settings->tenantKey(),
        );
        $limit = $this->settings->providerRequestsPerMinute();
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }
}
