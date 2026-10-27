<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\Paypal\Services\PaypalService;

class PaymentInitiationFlowTest extends TestCase
{
    public function test_paypal_approval_redirect_accepts_only_paypal_owned_https_urls(): void
    {
        self::assertSame(
            'https://www.paypal.com/checkoutnow?token=ORDER123',
            PaypalService::approvalUrl([
                'links' => [[
                    'rel'  => 'payer-action',
                    'href' => 'https://www.paypal.com/checkoutnow?token=ORDER123',
                ]],
            ])
        );
        self::assertSame(
            'https://www.sandbox.paypal.com/checkoutnow?token=ORDER123',
            PaypalService::approvalUrl([
                'approval_url' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER123',
            ])
        );
        self::assertNull(PaypalService::approvalUrl([
            'links' => [[
                'rel'  => 'approve',
                'href' => 'https://paypal.example.com/phishing',
            ]],
        ]));
        self::assertNull(PaypalService::approvalUrl([
            'links' => [[
                'rel'  => 'approve',
                'href' => 'http://www.paypal.com/checkoutnow?token=ORDER123',
            ]],
        ]));
    }

    public function test_redirect_capable_plugins_can_bypass_the_intermediate_payment_page(): void
    {
        $root          = dirname(__DIR__, 2);
        $payment       = (string) file_get_contents($root . '/beike/Shop/Services/PaymentService.php');
        $paypal        = (string) file_get_contents($root . '/plugins/Paypal/Bootstrap.php');
        $paypalRoutes  = (string) file_get_contents($root . '/plugins/Paypal/Routes/shop.php');
        $platform      = (string) file_get_contents($root . '/plugins/LongosPlatformPay/Bootstrap.php');
        $platformStart = (string) file_get_contents($root . '/plugins/LongosPlatformPay/Services/DirectPaymentService.php');

        self::assertStringContainsString("hook_filter('service.payment.pay.start'", $payment);
        self::assertStringContainsString("'allow_direct'", $payment);
        self::assertStringContainsString('$startResponse instanceof Response', $payment);
        self::assertStringContainsString("add_hook_filter('service.payment.pay.start'", $paypal);
        self::assertStringContainsString('->startOrder(', $paypal);
        self::assertStringContainsString('/rest/return', $paypalRoutes);
        self::assertStringContainsString('/rest/cancel', $paypalRoutes);
        self::assertStringContainsString("add_hook_filter('service.payment.pay.start'", $platform);
        self::assertStringContainsString("availableProviders() !== ['paypal']", $platformStart);
        self::assertStringContainsString("redirect()->away(\$result['approval_url'])", $platformStart);

        $paypalController = (string) file_get_contents($root . '/plugins/Paypal/Controllers/PaypalController.php');
        self::assertStringContainsString('order_checkout_result_url(', $paypalController);
        self::assertStringContainsString('isProviderBoundReturn(', $paypalController);
        self::assertStringContainsString('order_payment_url(', $paypalController);

        $orderController = (string) file_get_contents($root . '/beike/Shop/Http/Controllers/OrderController.php');
        self::assertStringContainsString('$authorized = false;', $orderController);
        self::assertStringContainsString('$order && $authorized', $orderController);
        self::assertStringContainsString('rememberGuestOrderNumber(', $orderController);
    }

    public function test_large_order_numbers_never_cross_the_browser_as_javascript_numbers(): void
    {
        $root     = dirname(__DIR__, 2);
        $platform = (string) file_get_contents($root . '/plugins/LongosPlatformPay/Views/checkout/payment.blade.php');
        $stripe   = (string) file_get_contents($root . '/plugins/Stripe/Views/checkout/payment.blade.php');
        $paypal   = (string) file_get_contents($root . '/plugins/Paypal/Views/checkout/payment.blade.php');
        $order    = (string) file_get_contents($root . '/beike/Models/Order.php');

        self::assertStringContainsString("'number'              => 'string'", $order);
        self::assertStringContainsString("@json((string) (\$order->number ?? ''))", $platform);
        self::assertStringContainsString("@json((string) (\$order->number ?? ''))", $stripe);
        self::assertStringContainsString('$paypalOrderNumber = (string)', $paypal);
        self::assertStringNotContainsString('order_number:@json($order->number', $platform);
        self::assertStringNotContainsString('const orderNumber = @json($order->number', $stripe);
    }

    public function test_platform_pay_starts_each_provider_from_the_original_checkout(): void
    {
        $root       = dirname(__DIR__, 2);
        $bootstrap  = (string) file_get_contents($root . '/plugins/LongosPlatformPay/Bootstrap.php');
        $inline     = (string) file_get_contents($root . '/plugins/LongosPlatformPay/Views/checkout/inline.blade.php');
        $controller = (string) file_get_contents($root . '/plugins/LongosPlatformPay/Controllers/CheckoutController.php');
        $checkout   = (string) file_get_contents($root . '/themes/default/checkout.blade.php');

        self::assertStringContainsString("add_hook_blade('checkout.bottom'", $bootstrap);
        self::assertStringContainsString('window.longosCheckoutPaymentHandlers.longos_platform_pay', $inline);
        self::assertStringContainsString('await request(config.sessionUrl, {order_number:orderNumber})', $inline);
        self::assertStringContainsString('window.location.assign(created.approval_url)', $inline);
        self::assertStringContainsString('stripe.confirmCardPayment(', $inline);
        self::assertStringContainsString('data.endpoints.stripe_complete', $inline);
        self::assertStringNotContainsString('card_number:', $inline);
        self::assertStringNotContainsString('card_cvc:', $inline);
        self::assertStringContainsString("'stripe' => in_array('stripe', \$providers, true)", $controller);
        self::assertStringContainsString("config('longos.checkout_locale', 'en')", $controller);
        self::assertStringContainsString("trans('LongosPlatformPay::common.session_failed'", $controller);
        self::assertStringNotContainsString('暂时无法连接主站支付', $controller);
        self::assertStringContainsString('failure.checkoutMessage = message', $inline);
        self::assertStringContainsString("'orderPaymentUrlTemplate'", $inline);
        self::assertStringContainsString('recoveryData?.checkout_url || tenantRecoveryUrl', $inline);
        self::assertStringContainsString('error?.checkoutMessage', $checkout);
        self::assertStringContainsString('payment_recovery_url', $checkout);
        self::assertStringContainsString('window.location.replace(confirmedOrderRecoveryUrl)', $checkout);
        self::assertStringContainsString('if (event.persisted) window.location.reload()', $checkout);

        $englishCheckout = require $root . '/lang/en/shop/checkout.php';
        self::assertStringContainsString('directly from this checkout', $englishCheckout['platform_pay_help']);
        self::assertStringNotContainsString('main-site payment page', $englishCheckout['platform_pay_help']);
    }

    public function test_platform_session_public_errors_do_not_expose_control_plane_diagnostics(): void
    {
        $controller = new \Plugin\LongosPlatformPay\Controllers\CheckoutController;
        $method     = new \ReflectionMethod($controller, 'publicErrorCode');

        self::assertSame('payment_service_unavailable', $method->invoke($controller, 'control_schema_outdated'));
        self::assertSame('payment_service_unavailable', $method->invoke($controller, 'control_storage_unavailable'));
        self::assertSame('session_busy', $method->invoke($controller, 'session_busy'));
        self::assertSame('order_unavailable', $method->invoke($controller, 'order_unavailable'));
    }

    public function test_saas_upgrade_discovers_guarded_control_plane_migrations(): void
    {
        $root      = dirname(__DIR__, 2);
        $upgrade   = (string) file_get_contents($root . '/app/Console/Commands/SaasUpgrade.php');
        $migration = (string) file_get_contents($root . '/database/migrations/2026_10_26_020000_normalize_platform_payment_timestamps_to_utc.php');

        self::assertStringContainsString("glob(database_path('migrations/*.php'))", $upgrade);
        self::assertStringContainsString("DB::getDefaultConnection() === 'tenant'", $upgrade);
        self::assertStringContainsString("DatabaseReadiness::assertControlPlaneMigrated('saas')", $upgrade);
        self::assertStringContainsString("DB::getDefaultConnection() === 'tenant'", $migration);
    }
}
