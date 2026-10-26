<?php

namespace Plugin\LongosPlatformPay;

use App\Services\PlatformPayment\PlatformPaymentSessionService;
use App\Services\PlatformPayment\PlatformPaymentSettings;
use Plugin\LongosPlatformPay\Services\DirectPaymentService;
use Throwable;

class Bootstrap
{
    public function boot(): void
    {
        add_hook_filter('repo.plugin.payment_methods', static function ($methods) {
            if (! (bool) data_get(current_site()?->meta, 'control_site', false)) {
                return $methods;
            }

            return collect($methods)
                ->reject(static fn ($method): bool => (string) ($method->code ?? '') === 'longos_platform_pay')
                ->values();
        });

        add_hook_filter('service.payment.pay.start', static function (array $context): array {
            if (($context['response'] ?? null) !== null
                || ! ($context['allow_direct'] ?? false)
                || (string) ($context['payment_method_code'] ?? '') !== PlatformPaymentSessionService::PAYMENT_CODE
                || ! ($context['order'] ?? null) instanceof \Beike\Models\Order) {
                return $context;
            }

            $context['response'] = app(DirectPaymentService::class)->start($context['order']);

            return $context;
        });

        add_hook_blade('checkout.bottom', static function ($callback, $output, $data) {
            if ((bool) data_get(current_site()?->meta, 'control_site', false)) {
                return $output;
            }

            try {
                $settings  = app(PlatformPaymentSettings::class);
                $snapshot  = $settings->snapshot();
                $providers = $settings->availableProvidersForSnapshot($snapshot);
                if ($providers === []) {
                    return $output;
                }

                return $output . view('LongosPlatformPay::checkout.inline', [
                    'providers'            => $providers,
                    'stripePublishableKey' => in_array('stripe', $providers, true)
                        ? (string) data_get($snapshot, 'stripe.publishable_key', '')
                        : '',
                ])->render();
            } catch (Throwable) {
                // Checkout must remain usable when the control plane is
                // temporarily unavailable; the payment method readiness
                // layer will hide an unusable platform channel.
                return $output;
            }
        });
    }
}
