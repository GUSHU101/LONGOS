<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PlatformPaymentRecoveryContractTest extends TestCase
{
    public function test_child_order_recovery_never_returns_to_an_empty_checkout(): void
    {
        $root       = dirname(__DIR__, 2);
        $sessions   = (string) file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaymentSessionService.php');
        $controller = (string) file_get_contents($root . '/app/Http/Controllers/PlatformPaymentController.php');
        $checkout   = (string) file_get_contents($root . '/beike/Shop/Http/Controllers/CheckoutController.php');
        $success    = (string) file_get_contents($root . '/themes/default/checkout/success.blade.php');

        self::assertStringContainsString('public function tenantRecoveryUrl(', $sessions);
        self::assertStringContainsString("shop_route('checkout.success'", $sessions);
        self::assertStringContainsString("tenantRecoveryUrl(\$session, 'error')", $controller);
        self::assertStringContainsString("tenantRecoveryUrl(\$session, 'cancelled')", $controller);
        self::assertStringNotContainsString("tenantCheckoutUrl(\$session) . '?payment_error=1'", $controller);
        self::assertStringContainsString("request('payment_status')", $checkout);
        self::assertStringContainsString('$platformPaymentNotice', $success);
        self::assertStringContainsString('!empty($platformPaymentNotice) && $paymentRequired', $success);
    }

    public function test_unavailable_hosted_checkout_has_a_safe_way_back_to_the_order(): void
    {
        $root       = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($root . '/app/Http/Controllers/PlatformPaymentController.php');
        $view       = (string) file_get_contents($root . '/resources/views/platform-pay/checkout.blade.php');

        self::assertStringContainsString("response()->view('platform-pay.checkout', \$viewData, 503)", $controller);
        self::assertStringContainsString("tenantRecoveryUrl(\$session, 'unavailable')", $controller);
        self::assertStringContainsString("__('platform_pay.unavailable_help')", $view);
        self::assertStringContainsString("__('platform_pay.return_to_store')", $view);
    }

    public function test_interrupted_provider_results_are_reconciled_automatically(): void
    {
        $root       = dirname(__DIR__, 2);
        $command    = (string) file_get_contents($root . '/app/Console/Commands/ReconcilePlatformPayments.php');
        $kernel     = (string) file_get_contents($root . '/app/Console/Kernel.php');
        $paypal     = (string) file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaypalGateway.php');
        $stripe     = (string) file_get_contents($root . '/app/Services/PlatformPayment/PlatformStripeGateway.php');
        $sessions   = (string) file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaymentSessionService.php');
        $model      = (string) file_get_contents($root . '/app/Saas/Models/PlatformPaymentSession.php');
        $controller = (string) file_get_contents($root . '/app/Http/Controllers/PlatformPaymentController.php');

        self::assertStringContainsString('saas:platform-pay:reconcile', $command);
        self::assertStringContainsString('->recoverCompleted(', $command);
        self::assertStringContainsString('$sessions->complete(', $command);
        self::assertStringContainsString("->where('expires_at', '<=', now())", $command);
        self::assertStringContainsString("\$schedule->command('saas:platform-pay:reconcile')", $kernel);
        self::assertStringContainsString('->withoutOverlapping(60)', $kernel);
        self::assertStringContainsString('return $failed === 0 ? self::SUCCESS : self::FAILURE', $command);
        self::assertStringContainsString('public function recoverCompleted(', $paypal);
        self::assertStringContainsString('showOrderDetails($paypalOrderId)', $paypal);
        self::assertStringContainsString("if (\$status === 'APPROVED')", $paypal);
        self::assertStringContainsString('return $this->capture($session, $paypalOrderId)', $paypal);
        self::assertStringContainsString('private function assertOrderBinding(', $paypal);
        self::assertStringContainsString('public function recoverCompleted(', $stripe);
        self::assertStringContainsString('paymentIntents->retrieve(', $stripe);
        self::assertStringContainsString('public function cancelUnresolved(', $stripe);
        self::assertStringContainsString('paymentIntents->cancel(', $stripe);
        self::assertStringContainsString("'expires_at'   => now()->addHours(3)", $sessions);
        self::assertStringContainsString('->financiallyUnsettled()', $command);
        self::assertStringContainsString('public function resolveProviderResult(', $sessions);
        self::assertStringContainsString('RECOVERY_WINDOW_DAYS', $model);
        self::assertStringContainsString('resolveProviderResult($token)', $controller);
        self::assertStringContainsString('$candidate->isFinanciallyRecoverable()', $sessions);
        self::assertStringContainsString("'active_key' => \$financiallyRecoverable ? \$session->active_key : null", $sessions);
        self::assertStringContainsString('$releasedStale = PlatformPaymentSession::query()', $command);
        self::assertStringContainsString("->where('provider', 'paypal')", $command);
        self::assertStringContainsString('$gateway->cancelUnresolved(', $command);
    }

    public function test_child_and_hosted_requests_have_bounded_network_waits(): void
    {
        $root   = dirname(__DIR__, 2);
        $child  = (string) file_get_contents($root . '/plugins/LongosPlatformPay/Views/checkout/payment.blade.php');
        $hosted = (string) file_get_contents($root . '/resources/views/platform-pay/checkout.blade.php');

        self::assertStringContainsString('new AbortController()', $child);
        self::assertStringContainsString('controller.abort()', $child);
        self::assertStringContainsString("window.addEventListener('pageshow', resetButton)", $child);
        self::assertStringContainsString('new AbortController()', $hosted);
        self::assertStringContainsString("__('platform_pay.status.timeout')", $hosted);
    }

    public function test_failed_provider_creation_releases_only_an_unbound_claim(): void
    {
        $root       = dirname(__DIR__, 2);
        $sessions   = (string) file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaymentSessionService.php');
        $controller = (string) file_get_contents($root . '/app/Http/Controllers/PlatformPaymentController.php');

        self::assertStringContainsString('public function releaseUnboundProviderClaim(', $sessions);
        self::assertStringContainsString('$locked->provider_order_id !== null', $sessions);
        self::assertStringContainsString("'provider' => null", $sessions);
        self::assertStringContainsString('if ($session instanceof PlatformPaymentSession && ! $providerCreated)', $controller);
        self::assertStringContainsString("\$this->releaseClaim(\$session, 'paypal')", $controller);
        self::assertStringContainsString("\$this->releaseClaim(\$session, 'stripe')", $controller);
    }
}
