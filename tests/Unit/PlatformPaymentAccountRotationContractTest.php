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
        self::assertStringContainsString("'payment_account_id' => \$account?->id", $sessions);
        self::assertStringContainsString("'callback_host'      => \$account?->callback_host", $sessions);
        self::assertStringContainsString('isSessionPaymentRequest', $hostGuard);
        self::assertStringContainsString('callbackPaymentRequest && $this->accounts->isCallbackHost', $hostGuard);
        self::assertStringContainsString('EnsurePlatformPaymentHost::isSessionPaymentRequest($request)', $redirect);
    }

    public function test_only_an_untouched_session_can_move_to_the_next_account(): void
    {
        $root       = dirname(__DIR__, 2);
        $sessions   = (string) file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaymentSessionService.php');
        $controller = (string) file_get_contents($root . '/app/Http/Controllers/PlatformPaymentController.php');
        $accounts   = (string) file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaymentAccountService.php');
        $health     = (string) file_get_contents($root . '/app/Console/Commands/CheckPlatformPaymentAccounts.php');
        $kernel     = (string) file_get_contents($root . '/app/Console/Kernel.php');

        self::assertStringContainsString('rotatePaypalAccountAfterPreOrderFailure', $sessions);
        self::assertStringContainsString('$locked->provider !== null', $sessions);
        self::assertStringContainsString('$locked->provider_order_id !== null', $sessions);
        self::assertStringContainsString('$locked->status !== PlatformPaymentSession::STATUS_PENDING', $sessions);
        self::assertStringContainsString('selectNextActivePaypalAccount((int) $locked->payment_account_id)', $sessions);
        self::assertStringContainsString('PlatformPaymentAccountUnavailable', $controller);
        self::assertStringContainsString('Only an OAuth/credential availability failure is safe', $controller);
        self::assertStringContainsString('replaceCircuitOpenPreOrderSession', $controller);
        self::assertStringContainsString('provider_order_id !== null', $controller);
        self::assertStringContainsString('consecutive_failures', $accounts);
        self::assertStringContainsString("'circuit_open_until'", $accounts);
        self::assertStringContainsString('isUnavailableForNewSessions', $accounts);
        self::assertStringContainsString('lockForUpdate()', $accounts);
        self::assertStringContainsString('enabledPaypalAccountsForHealth', $accounts);
        self::assertStringContainsString('enabledPaypalAccountsForHealth($limit)', $health);
        self::assertStringContainsString('saas:platform-pay:accounts-health', $health);
        self::assertStringContainsString('snapshotForPaypalAccount($account)', $health);
        self::assertStringContainsString("\$schedule->command('saas:platform-pay:accounts-health')", $kernel);
    }

    public function test_managed_paypal_handoff_keeps_the_sdk_and_return_route_on_the_account_host(): void
    {
        $root     = dirname(__DIR__, 2);
        $checkout = (string) file_get_contents($root . '/plugins/LongosPlatformPay/Controllers/CheckoutController.php');
        $inline   = (string) file_get_contents($root . '/plugins/LongosPlatformPay/Views/checkout/inline.blade.php');
        $direct   = (string) file_get_contents($root . '/plugins/LongosPlatformPay/Services/DirectPaymentService.php');
        $paypal   = (string) file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaypalGateway.php');

        self::assertStringContainsString("'hosted_checkout_required'", $checkout);
        self::assertStringContainsString('data.hosted_checkout_required && data.checkout_url', $inline);
        self::assertStringContainsString("created['session']->callback_host", $direct);
        self::assertStringContainsString('paymentUrlForSession($session, "_longos/pay/{$token}/return/paypal")', $paypal);
        self::assertStringContainsString('paymentUrlForSession($session, "_longos/pay/{$token}/cancel")', $paypal);
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
}
