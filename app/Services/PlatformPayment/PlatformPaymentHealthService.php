<?php

namespace App\Services\PlatformPayment;

use Throwable;

final class PlatformPaymentHealthService
{
    public function __construct(private readonly PlatformPaymentSettings $settings) {}

    /**
     * Verify the credentials against the real provider APIs without creating
     * a charge. The response deliberately contains no account keys, provider
     * payloads, or exception messages.
     *
     * @return array{state: string, healthy: bool, providers: array<string, array{state: string, message: string}>}
     */
    public function check(): array
    {
        try {
            $snapshot = $this->settings->snapshot();
        } catch (Throwable) {
            return [
                'state'     => 'unavailable',
                'healthy'   => false,
                'providers' => [],
            ];
        }

        $configured = $this->settings->configuredProviders($snapshot);
        $providers  = [];
        foreach (['paypal', 'stripe'] as $provider) {
            if (! (bool) ($snapshot['share_' . $provider] ?? false)) {
                continue;
            }

            if (! in_array($provider, $configured, true)) {
                $providers[$provider] = [
                    'state'   => 'incomplete',
                    'message' => trans("LongosPlatformPay::common.admin.health_{$provider}_incomplete"),
                ];

                continue;
            }

            try {
                if ($provider === 'paypal') {
                    app(PlatformPaypalGateway::class)->healthCheck();
                } else {
                    app(PlatformStripeGateway::class)->healthCheck();
                }

                $providers[$provider] = [
                    'state'   => 'ready',
                    'message' => trans("LongosPlatformPay::common.admin.health_{$provider}_ready"),
                ];
            } catch (Throwable) {
                $providers[$provider] = [
                    'state'   => 'failed',
                    'message' => trans("LongosPlatformPay::common.admin.health_{$provider}_failed"),
                ];
            }
        }

        $states  = array_column($providers, 'state');
        $healthy = $providers !== [] && ! array_diff($states, ['ready']);
        $state   = $healthy
            ? 'ready'
            : (in_array('ready', $states, true) ? 'degraded' : 'unavailable');

        return compact('state', 'healthy', 'providers');
    }
}
