<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsurePlatformPaymentHost;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class PlatformPaymentAccountRotationContractTest extends TestCase
{
    public function test_account_pool_binds_the_callback_host_to_each_immutable_session(): void
    {
        $root      = dirname(__DIR__, 2);
        $migration = (string) file_get_contents($root . '/database/migrations/2026_11_06_000000_add_platform_payment_account_rotation.php');
        $sessions  = (string) file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaymentSessionService.php');
        $hostGuard = (string) file_get_contents($root . '/app/Http/Middleware/EnsurePlatformPaymentHost.php');
        $redirect  = (string) file_get_contents($root . '/app/Http/Middleware/RedirectSecondaryDomain.php');

        self::assertStringContainsString("Schema::create('saas_platform_payment_accounts'", $migration);
        self::assertStringContainsString("unique('sppa_callback_host_uq')", $migration);
        self::assertStringContainsString("'payment_account_id'", $migration);
        self::assertStringContainsString("'callback_host'", $migration);
        self::assertMatchesRegularExpression("/'payment_account_id'\\s*=>\\s*\\\$account\\?->id/", $sessions);
        self::assertMatchesRegularExpression("/'callback_host'\\s*=>\\s*\\\$account\\?->callback_host/", $sessions);
        self::assertStringContainsString('isSessionPaymentRequest', $hostGuard);
        self::assertStringContainsString('callbackPaymentRequest && $this->accounts->isCallbackHost', $hostGuard);
        self::assertStringContainsString('EnsurePlatformPaymentHost::isSessionPaymentRequest($request)', $redirect);
    }

    public function test_only_an_untouched_session_can_move_to_the_next_account(): void
    {
        $root       = dirname(__DIR__, 2);
        $sessions   = (string) file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaymentSessionService.php');
        $controller = (string) file_get_contents($root . '/app/Http/Controllers/PlatformPaymentController.php');
        $direct     = (string) file_get_contents($root . '/plugins/LongosPlatformPay/Services/DirectPaymentService.php');
        $accounts   = (string) file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaymentAccountService.php');
        $health     = (string) file_get_contents($root . '/app/Console/Commands/CheckPlatformPaymentAccounts.php');
        $kernel     = (string) file_get_contents($root . '/app/Console/Kernel.php');

        self::assertStringContainsString('rotatePaypalAccountAfterPreOrderFailure', $sessions);
        self::assertMatchesRegularExpression('/\$locked->provider\s*!==\s*null/', $sessions);
        self::assertMatchesRegularExpression('/\$locked->provider_order_id\s*!==\s*null/', $sessions);
        self::assertMatchesRegularExpression('/\$locked->status\s*!==\s*PlatformPaymentSession::STATUS_PENDING/', $sessions);
        self::assertStringContainsString('selectNextActivePaypalAccount((int) $locked->payment_account_id)', $sessions);
        self::assertStringContainsString('PlatformPaymentAccountUnavailable', $controller);
        self::assertStringContainsString('Only an OAuth/credential availability failure is safe', $controller);
        self::assertStringContainsString('PlatformPaymentAccountUnavailable', $direct);
        self::assertStringContainsString('rotatePaypalAccountAfterPreOrderFailure($session)', $direct);
        self::assertStringContainsString('for ($attempt = 0; $attempt < 2; $attempt++)', $direct);
        self::assertStringContainsString('consecutive_failures', $accounts);
        self::assertStringContainsString("'circuit_open_until'", $accounts);
        self::assertStringContainsString('isUnavailableForNewSessions', $accounts);
        self::assertStringContainsString("health_status === 'failed'", $accounts);
        self::assertStringContainsString('routableActivePaypalQuery', $accounts);
        self::assertStringContainsString("whereIn('callback_host', Domain::query()->routable()->select('host'))", $accounts);
        self::assertStringContainsString('recordUnavailable', $accounts);
        self::assertMatchesRegularExpression("/'circuit_open_until'\\s*=>\\s*now\\('UTC'\\)->addMinutes\\(10\\)/", $accounts);
        self::assertStringContainsString('lockForUpdate()', $accounts);
        self::assertStringContainsString('enabledPaypalAccountsForHealth', $accounts);
        self::assertStringContainsString('enabledPaypalAccountsForHealth($limit)', $health);
        self::assertStringContainsString('recordUnavailable($account)', $health);
        self::assertStringContainsString('saas:platform-pay:accounts-health', $health);
        self::assertStringContainsString('snapshotForPaypalAccount($account)', $health);
        $adminController = (string) file_get_contents($root . '/beike/Admin/Http/Controllers/PlatformPaymentAccountController.php');
        self::assertStringContainsString('recordUnavailable($account)', $adminController);
        self::assertStringContainsString("\$schedule->command('saas:platform-pay:accounts-health')", $kernel);
    }

    public function test_managed_paypal_handoff_opens_paypal_without_a_platform_interstitial(): void
    {
        $root       = dirname(__DIR__, 2);
        $checkout   = (string) file_get_contents($root . '/plugins/LongosPlatformPay/Controllers/CheckoutController.php');
        $inline     = (string) file_get_contents($root . '/plugins/LongosPlatformPay/Views/checkout/inline.blade.php');
        $direct     = (string) file_get_contents($root . '/plugins/LongosPlatformPay/Services/DirectPaymentService.php');
        $payment    = (string) file_get_contents($root . '/plugins/LongosPlatformPay/Views/checkout/payment.blade.php');
        $controller = (string) file_get_contents($root . '/app/Http/Controllers/PlatformPaymentController.php');
        $paypal     = (string) file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaypalGateway.php');

        self::assertStringNotContainsString('checkout_url', $checkout);
        self::assertStringNotContainsString('hosted_checkout_required', $inline);
        self::assertStringContainsString('const createPaypalOrder = async data =>', $inline);
        self::assertStringContainsString('window.location.assign(created.approval_url)', $inline);
        self::assertStringContainsString('for ($attempt = 0; $attempt < 2; $attempt++)', $direct);
        self::assertStringContainsString("redirect()->away(\$result['approval_url'])", $direct);
        self::assertStringNotContainsString("redirect()->away(\$created['checkout_url'])", $direct);
        self::assertStringContainsString('paymentUrlForSession($session, "_longos/pay/{$token}/return/paypal")', $paypal);
        self::assertStringContainsString('paymentUrlForSession($session, "_longos/pay/{$token}/cancel")', $paypal);
        self::assertStringContainsString('const createPaypalOrder = async data =>', $payment);
        self::assertStringContainsString('window.location.assign(result.approval_url)', $payment);
        self::assertStringNotContainsString('restartOnRotatedPaypalAccount', $payment);
        self::assertStringNotContainsString('restart_url', $controller);
        self::assertStringNotContainsString("'platform-pay.checkout'", $controller);
        self::assertStringContainsString('auditExpectedMerchantWhenReturned', $paypal);
        self::assertStringContainsString('Platform Pay received an unexpected PayPal merchant identifier.', $paypal);
        self::assertStringNotContainsString("\$unit['payee'] = ['merchant_id' => \$this->settings['merchant_id']]", $paypal);
        self::assertStringNotContainsString("query.set('merchant-id'", $inline);
    }

    public function test_callback_alias_allows_only_capability_token_payment_routes(): void
    {
        $token = str_repeat('a', 64);

        self::assertTrue(EnsurePlatformPaymentHost::isSessionPaymentRequest(Request::create("/_longos/pay/{$token}")));
        self::assertTrue(EnsurePlatformPaymentHost::isSessionPaymentRequest(Request::create("/_longos/pay/{$token}/paypal/create", 'POST')));
        self::assertTrue(EnsurePlatformPaymentHost::isSessionPaymentRequest(Request::create("/_longos/pay/{$token}/return/paypal")));
        self::assertFalse(EnsurePlatformPaymentHost::isSessionPaymentRequest(Request::create('/_longos/pay/webhooks/stripe', 'POST')));
        self::assertFalse(EnsurePlatformPaymentHost::isSessionPaymentRequest(Request::create('/_longos/pay/not-a-token')));
    }

    public function test_deployment_guards_require_the_account_rotation_schema(): void
    {
        $root      = dirname(__DIR__, 2);
        $health    = (string) file_get_contents($root . '/app/Http/Controllers/HealthController.php');
        $preflight = (string) file_get_contents($root . '/app/Console/Commands/SaasPreflight.php');
        $settings  = (string) file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaymentSettings.php');

        self::assertStringContainsString("'saas_platform_payment_accounts'", $health);
        self::assertStringContainsString("'saas_platform_payment_accounts'", $preflight);
        self::assertStringContainsString("hasColumn('saas_platform_payment_sessions', 'payment_account_id')", $settings);
        self::assertStringContainsString("hasColumn('saas_platform_payment_sessions', 'callback_host')", $settings);
        self::assertStringContainsString("hasTable('saas_platform_payment_accounts')", $settings);
    }

    public function test_callback_domains_are_protected_from_platform_domain_mutations(): void
    {
        $root      = dirname(__DIR__, 2);
        $lifecycle = (string) file_get_contents($root . '/app/Saas/SiteLifecycleService.php');
        $accounts  = (string) file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaymentAccountService.php');

        self::assertStringContainsString('protectedPlatformPaymentCallbackHosts', $lifecycle);
        self::assertStringContainsString("whereNotIn('host', \$protectedCallbackHosts)", $lifecycle);
        self::assertStringContainsString('isProtectedPlatformPaymentCallbackHost', $lifecycle);
        self::assertStringContainsString('支付回传域名', $lifecycle);
        self::assertStringContainsString('isCallbackHostRoutable', $accounts);
        self::assertMatchesRegularExpression("/'enabled'\\s*=>\\s*false/", $accounts);

        $controller = (string) file_get_contents($root . '/app/Http/Controllers/PlatformPaymentController.php');
        self::assertStringContainsString("->header('Cache-Control', 'no-store, private, max-age=0')", $controller);
        self::assertStringContainsString("->header('X-Frame-Options', 'DENY')", $controller);
    }
}
