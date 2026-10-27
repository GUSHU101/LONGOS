<?php

namespace App\Services\PlatformPayment;

use Closure;
use Throwable;

final class PlatformPaymentHealthService
{
    /**
     * Optional probes keep the orchestration independently testable while the
     * service container uses the real provider gateways in production.
     *
     * @param (Closure(array<string, mixed>): void)|null $paypalProbe
     * @param (Closure(array<string, mixed>): void)|null $stripeProbe
     */
    public function __construct(
        private readonly PlatformPaymentSettings $settings,
        private readonly ?Closure $paypalProbe = null,
        private readonly ?Closure $stripeProbe = null,
    ) {}

    /**
     * Verify the credentials against the real provider APIs without creating
     * a charge. The response deliberately contains no account keys, provider
     * payloads, or exception messages.
     *
     * @return array{state: string, healthy: bool, providers: array<string, array{state: string, message: string}>}
     */
    public function check(array $overrides = []): array
    {
        try {
            $snapshot = $overrides === []
                ? $this->settings->snapshot()
                : $this->settings->snapshotWithOverrides($overrides);
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
                    $this->probePaypal($snapshot);
                } else {
                    $this->probeStripe($snapshot);
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

    /** @param array<string, mixed> $snapshot */
    private function probePaypal(array $snapshot): void
    {
        if ($this->paypalProbe) {
            ($this->paypalProbe)($snapshot);

            return;
        }

        (new PlatformPaypalGateway($this->settings, $snapshot))->healthCheck();
    }

    /** @param array<string, mixed> $snapshot */
    private function probeStripe(array $snapshot): void
    {
        if ($this->stripeProbe) {
            ($this->stripeProbe)($snapshot);

            return;
        }

        (new PlatformStripeGateway($this->settings, $snapshot))->healthCheck();
    }
}
