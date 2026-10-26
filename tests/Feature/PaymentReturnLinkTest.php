<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\TestCase;

class PaymentReturnLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_payment_result_link_survives_a_tenant_host_change_and_rejects_tampering(): void
    {
        $url      = order_checkout_result_url('261026001234567890', 'error');
        $path     = $this->pathAndQuery($url);
        $oldHost  = Request::create('https://old-shop.example' . $path);
        $newHost  = Request::create('https://new-shop.example' . $path);
        $tampered = Request::create('https://new-shop.example' . str_replace('261026001234567890', '261026001234567891', $path));

        self::assertTrue(URL::hasValidSignature($oldHost, absolute: false));
        self::assertTrue(URL::hasValidSignature($newHost, absolute: false));
        self::assertFalse(URL::hasValidSignature($tampered, absolute: false));
        self::assertStringContainsString('payment_status=error', $url);
        self::assertStringContainsString('expires=', $url);
    }

    public function test_retry_payment_link_signs_the_order_and_manual_recovery_mode(): void
    {
        $url      = order_payment_url((object) ['number' => '261026009876543210'], true);
        $request  = Request::create('https://custom-domain.example' . $this->pathAndQuery($url));
        $tampered = Request::create('https://custom-domain.example' . str_replace('manual=1', 'manual=0', $this->pathAndQuery($url)));

        self::assertTrue(URL::hasValidSignature($request, absolute: false));
        self::assertFalse(URL::hasValidSignature($tampered, absolute: false));
        self::assertStringContainsString('/orders/261026009876543210/pay', $url);
    }

    public function test_payment_link_lifetime_is_clamped_to_a_safe_range(): void
    {
        config(['longos.payment_action_link_minutes' => 100000]);

        $url       = order_checkout_result_url('261026001111111111');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $expiresAt = (int) ($query['expires'] ?? 0);

        self::assertGreaterThan(now()->addMinutes(1439)->timestamp, $expiresAt);
        self::assertLessThanOrEqual(now()->addMinutes(1440)->timestamp, $expiresAt);
    }

    private function pathAndQuery(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH) . '?' . (string) parse_url($url, PHP_URL_QUERY);
    }
}
