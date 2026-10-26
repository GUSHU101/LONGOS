<?php

/**
 * Bootstrap.php
 *
 * @copyright  2023 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2023-08-17 15:15:27
 * @modified   2023-08-17 15:15:27
 */

namespace Plugin\Paypal;

class Bootstrap
{
    public function boot(): void
    {
        $this->adminSettingPage();
        $this->directCheckout();
    }

    private function directCheckout(): void
    {
        add_hook_filter('service.payment.pay.start', static function (array $context): array {
            if (($context['response'] ?? null) !== null
                || ! ($context['allow_direct'] ?? false)
                || (string) ($context['payment_method_code'] ?? '') !== 'paypal'
                || ! ($context['order'] ?? null) instanceof \Beike\Models\Order) {
                return $context;
            }

            $context['response'] = app(\Plugin\Paypal\Controllers\PaypalController::class)
                ->startOrder($context['order']);

            return $context;
        });
    }

    private function adminSettingPage(): void
    {
        add_hook_blade('admin.plugin.form', function ($callback, $output, $data) {
            $code = $data['plugin']->code;
            if ($code == 'paypal') {
                return view('Paypal::admin.config_form', $data)->render();
            }

            return $output;
        }, 1);
    }
}
