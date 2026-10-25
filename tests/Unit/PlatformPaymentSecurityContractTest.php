<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PlatformPaymentSecurityContractTest extends TestCase
{
    public function test_hosted_payment_routes_are_control_host_only_and_provider_returns_stay_on_main_site(): void
    {
        $webRoutes = file_get_contents(dirname(__DIR__, 2) . '/routes/web.php');
        $paypal    = file_get_contents(dirname(__DIR__, 2) . '/app/Services/PlatformPayment/PlatformPaypalGateway.php');
        $stripe    = file_get_contents(dirname(__DIR__, 2) . '/resources/views/platform-pay/checkout.blade.php');

        self::assertStringContainsString("->middleware(['platform.payment', 'throttle:platform-payment'])", $webRoutes);
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
        self::assertStringContainsString('random_bytes(32)', $service);
    }

    public function test_child_store_never_receives_platform_credentials(): void
    {
        $checkout = file_get_contents(dirname(__DIR__, 2) . '/plugins/LongosPlatformPay/Views/checkout/payment.blade.php');

        self::assertStringNotContainsString('publishable_key', $checkout);
        self::assertStringNotContainsString('client_id', $checkout);
        self::assertStringNotContainsString('secret_key', $checkout);
        self::assertStringContainsString('checkout_url', $checkout);
    }
}
