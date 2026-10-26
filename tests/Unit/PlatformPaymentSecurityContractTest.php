<?php

namespace Tests\Unit;

use App\Services\PlatformPayment\PlatformPaymentSettings;
use PHPUnit\Framework\TestCase;

class PlatformPaymentSecurityContractTest extends TestCase
{
    public function test_hosted_payment_routes_are_control_host_only_and_provider_returns_stay_on_main_site(): void
    {
        $webRoutes = file_get_contents(dirname(__DIR__, 2) . '/routes/web.php');
        $paypal    = file_get_contents(dirname(__DIR__, 2) . '/app/Services/PlatformPayment/PlatformPaypalGateway.php');
        $stripe    = file_get_contents(dirname(__DIR__, 2) . '/resources/views/platform-pay/checkout.blade.php');

        self::assertStringContainsString("->middleware(['platform.payment', 'throttle:platform-payment'])", $webRoutes);
        self::assertStringContainsString("name('platform-pay.status')", $webRoutes);
        self::assertStringContainsString('_longos/pay/{$token}/return/paypal', $paypal);
        self::assertStringContainsString('_longos/pay/{$token}/cancel', $paypal);
        self::assertStringContainsString('/_longos/pay/${token}/return/stripe', $stripe);
    }

    public function test_central_session_stores_only_a_hash_of_the_capability_token(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/2026_10_24_010000_create_saas_platform_payment_sessions_table.php');
        $service   = file_get_contents(dirname(__DIR__, 2) . '/app/Services/PlatformPayment/PlatformPaymentSessionService.php');

        self::assertStringContainsString("char('token_hash', 64)->unique('spp_token_uq')", $migration);
        self::assertStringNotContainsString("->string('token'", $migration);
        self::assertStringContainsString("hash('sha256', \$token)", $service);
        self::assertStringContainsString("hash_hmac('sha256', 'longos-platform-payment|'", $service);
        self::assertStringContainsString("'active_key'   => \$activeKey", $service);
        self::assertStringContainsString('catch (UniqueConstraintViolationException $exception)', $service);
        self::assertStringContainsString("->where('active_key', \$activeKey)", $service);
        self::assertStringContainsString("unique('spp_active_uq')", (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/2026_10_25_010000_add_active_key_to_saas_platform_payment_sessions_table.php'));
    }

    public function test_child_store_receives_only_provider_public_keys(): void
    {
        $root       = dirname(__DIR__, 2);
        $checkout   = file_get_contents($root . '/plugins/LongosPlatformPay/Views/checkout/payment.blade.php');
        $controller = file_get_contents($root . '/plugins/LongosPlatformPay/Controllers/CheckoutController.php');

        self::assertStringContainsString('data.stripe.publishable_key', $checkout);
        self::assertStringContainsString('data.paypal.client_id', $checkout);
        self::assertStringNotContainsString('secret_key', $checkout);
        self::assertStringNotContainsString('paypal_secret', $checkout);
        self::assertStringNotContainsString('webhook_secret', $checkout);
        self::assertStringNotContainsString("data_get(\$snapshot, 'paypal.secret'", $controller);
        self::assertStringNotContainsString("data_get(\$snapshot, 'stripe.secret_key'", $controller);
        self::assertStringNotContainsString("data_get(\$snapshot, 'stripe.webhook_secret'", $controller);
        self::assertStringContainsString('checkout_url', $checkout);
    }

    public function test_child_payment_page_embeds_both_provider_sdks_and_keeps_returns_on_the_main_site(): void
    {
        $root       = dirname(__DIR__, 2);
        $routes     = file_get_contents($root . '/plugins/LongosPlatformPay/Routes/shop.php');
        $checkout   = file_get_contents($root . '/plugins/LongosPlatformPay/Views/checkout/payment.blade.php');
        $controller = file_get_contents($root . '/plugins/LongosPlatformPay/Controllers/CheckoutController.php');
        $headers    = file_get_contents($root . '/app/Http/Middleware/ApplySecurityHeaders.php');

        self::assertStringContainsString('/paypal/create', $routes);
        self::assertStringContainsString('/status', $routes);
        self::assertStringContainsString('/paypal/capture', $routes);
        self::assertStringContainsString('/stripe/intent', $routes);
        self::assertStringContainsString('/stripe/complete', $routes);
        self::assertStringContainsString('https://www.paypal.com/sdk/js?', $checkout);
        self::assertStringContainsString("'data-namespace':'LongosPlatformPayPal'", $checkout);
        self::assertStringContainsString('window.LongosPlatformPayPal.Buttons', $checkout);
        self::assertStringNotContainsString('window.paypal', $checkout);
        self::assertStringContainsString('https://js.stripe.com/v3/', $checkout);
        self::assertStringContainsString('buttons.isEligible()', $checkout);
        self::assertStringContainsString("card.on('ready'", $checkout);
        self::assertStringContainsString('disabled aria-busy="true"', $checkout);
        self::assertStringContainsString('stripe.confirmCardPayment(', $checkout);
        self::assertStringContainsString('return_url:data.return_url', $checkout);
        self::assertStringContainsString('_longos/pay/{$token}/return/stripe', $controller);
        self::assertStringContainsString('assertChildSession(', $controller);
        self::assertStringContainsString("route('shop.longos_platform_pay.status', ['token' => \$token], false)", $controller);
        self::assertStringContainsString("route('shop.longos_platform_pay.session', [], false)", $checkout);
        self::assertStringContainsString("'longos-platform-pay/*'", $headers);

        $hosted = file_get_contents($root . '/resources/views/platform-pay/checkout.blade.php');
        self::assertStringContainsString('data-namespace="LongosPlatformPayPal"', $hosted);
        self::assertStringContainsString('window.LongosPlatformPayPal.Buttons', $hosted);
        self::assertStringNotContainsString('window.paypal', $hosted);
        self::assertStringContainsString('buttons.isEligible()', $hosted);
        self::assertStringContainsString("card.on('ready'", $hosted);
        self::assertStringContainsString('disabled aria-busy="true"', $hosted);
        self::assertStringContainsString('methodUnavailable(', $hosted);
        self::assertStringContainsString("request('status', {}, 8000)", $hosted);
        self::assertStringContainsString('const apiRoot = `/_longos/pay/${encodeURIComponent(token)}`', $hosted);
        self::assertStringContainsString('fetch(`${apiRoot}/${path}`', $hosted);
        self::assertStringNotContainsString('fetch(path,', $hosted);
    }

    public function test_hosted_provider_marks_are_shared_by_checkout_and_footer_presentations(): void
    {
        self::assertSame(['paypal'], PlatformPaymentSettings::paymentMarksForProviders(['paypal']));
        self::assertSame(
            ['paypal', 'visa', 'mastercard', 'amex', 'discover', 'jcb', 'diners', 'unionpay', 'cartes_bancaires'],
            PlatformPaymentSettings::paymentMarksForProviders(['paypal', 'stripe', 'paypal'])
        );

        $root     = dirname(__DIR__, 2);
        $checkout = (string) file_get_contents($root . '/beike/Shop/Services/CheckoutService.php');
        $footer   = (string) file_get_contents($root . '/beike/Repositories/FooterRepo.php');
        $view     = (string) file_get_contents($root . '/themes/default/checkout.blade.php');

        self::assertStringContainsString("\$method['brand_marks']", $checkout);
        self::assertStringContainsString('paymentMarksForProviders($providers)', $footer);
        self::assertStringContainsString('public static function availablePaymentMarks(): array', $footer);
        self::assertStringContainsString('self::availablePaymentMarks()', $footer);
        self::assertStringContainsString('PAYMENT_MARKS_REQUEST_KEY', $footer);
        self::assertStringContainsString('renderCheckoutPaymentBrands(item.brand_marks)', $view);
        self::assertStringContainsString("__('shop/checkout.accepted_payment_methods')", $view);
    }

    public function test_disabled_channels_cannot_claim_new_sessions_and_completed_returns_are_idempotent(): void
    {
        $root       = dirname(__DIR__, 2);
        $sessions   = (string) file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaymentSessionService.php');
        $controller = (string) file_get_contents($root . '/app/Http/Controllers/PlatformPaymentController.php');

        self::assertStringContainsString('$this->settings->availableProviders()', $sessions);
        self::assertStringContainsString('$this->settings->runtimeIssues()', $sessions);
        self::assertStringContainsString('$lock->block(10)', $sessions);
        self::assertStringContainsString('catch (LockTimeoutException)', $sessions);
        self::assertStringContainsString('private function isCompletedBy(', $controller);
        self::assertStringContainsString("json_success('Payment already completed.'", $controller);
        self::assertStringContainsString('if (! $session instanceof PlatformPaymentSession)', $controller);
    }

    public function test_runtime_readiness_allows_one_healthy_shared_channel_to_stay_available(): void
    {
        $settings  = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/PlatformPayment/PlatformPaymentSettings.php');
        $readiness = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/PaymentPluginReadiness.php');
        $view      = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/platform-pay/checkout.blade.php');

        self::assertStringContainsString('public function runtimeIssues(', $settings);
        self::assertStringContainsString('availableProvidersForSnapshot($settings, false)', $settings);
        self::assertStringContainsString('->runtimeIssues(! $controlSite)', $readiness);
        self::assertStringContainsString('PlatformPaymentAmount::format(', $view);
        self::assertStringNotContainsString('number_format((float) $session->amount', $view);
    }

    public function test_runtime_channel_fallback_is_evaluated_from_real_provider_credentials(): void
    {
        $service  = (new \ReflectionClass(PlatformPaymentSettings::class))->newInstanceWithoutConstructor();
        $snapshot = [
            'enabled'      => true,
            'share_paypal' => true,
            'share_stripe' => true,
            'paypal'       => [
                'api_mode' => 'rest', 'client_id' => 'paypal-client', 'secret' => 'paypal-secret', 'merchant_id' => '',
            ],
            'stripe' => [
                'test_mode' => true, 'publishable_key' => '', 'secret_key' => '', 'webhook_secret' => '',
            ],
        ];

        self::assertSame(['paypal'], $service->availableProvidersForSnapshot($snapshot));
        self::assertSame([], $service->runtimeIssuesForSnapshot($snapshot));

        $snapshot['paypal']['secret'] = '';
        self::assertNotSame([], $service->runtimeIssuesForSnapshot($snapshot));

        $snapshot['enabled'] = false;
        self::assertNotSame([], $service->runtimeIssuesForSnapshot($snapshot));
    }

    public function test_release_pipeline_requires_the_independent_platform_plugin(): void
    {
        $root     = dirname(__DIR__, 2);
        $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');
        $deletion = (string) file_get_contents($root . '/app/Saas/SiteDeletionService.php');

        self::assertStringContainsString('LongosPlatformPay', $workflow);
        self::assertStringContainsString('plugins/LongosPlatformPay/Bootstrap.php', $workflow);
        self::assertStringContainsString('->deletionSensitive()', $deletion);
        self::assertStringContainsString('credentialChangeIssues($fields)', (string) file_get_contents(
            $root . '/beike/Admin/Http/Controllers/PluginController.php'
        ));
        self::assertStringContainsString('private function platformPaymentFields(', (string) file_get_contents(
            $root . '/beike/Admin/Http/Controllers/PluginController.php'
        ));
        $controller = (string) file_get_contents($root . '/beike/Admin/Http/Controllers/PluginController.php');
        $sessions   = (string) file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaymentSessionService.php');
        $paypal     = (string) file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaypalGateway.php');
        self::assertStringContainsString('PlatformPaymentSettings::CONFIGURATION_LOCK', $controller);
        self::assertStringContainsString('PlatformPaymentSettings::CONFIGURATION_LOCK', $sessions);
        self::assertStringContainsString('PlatformPaymentCredentials::preserveBlankSecrets($fields)', $controller);
        self::assertStringContainsString('safePlatformPaymentInput(', $controller);
        self::assertStringContainsString('Site::query()->whereKey($session->site_id)->lockForUpdate()', $sessions);
        self::assertStringContainsString('->deletionSensitive()', $deletion);
        self::assertStringContainsString("requestId('create', \$session)", $paypal);
        self::assertStringContainsString("requestId('capture', \$session)", $paypal);
    }
}
