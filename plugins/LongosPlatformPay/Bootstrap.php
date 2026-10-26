<?php

namespace Plugin\LongosPlatformPay;

use App\Services\PlatformPayment\PlatformPaymentSessionService;
use Plugin\LongosPlatformPay\Services\DirectPaymentService;

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
    }
}
