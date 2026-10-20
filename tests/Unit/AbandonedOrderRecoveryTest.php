<?php

namespace Tests\Unit;

use Beike\Models\AbandonedOrderRecovery;
use Beike\Models\Order;
use Beike\Models\OrderProduct;
use Beike\Services\AbandonedOrderRecoveryService;
use Beike\Services\StateMachineService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\TestCase;

class AbandonedOrderRecoveryTest extends TestCase
{
    private $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $this->app->make(Kernel::class)->bootstrap();
        config(['app.url' => 'https://shop.example.test']);
        URL::forceRootUrl('https://shop.example.test');
    }

    protected function tearDown(): void
    {
        URL::forceRootUrl(null);
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_candidate_state_enforces_delay_cooldown_limits_and_suppression(): void
    {
        $service  = new AbandonedOrderRecoveryService;
        $settings = AbandonedOrderRecoveryService::defaults();
        $order    = $this->order();

        self::assertSame('eligible', $service->deliveryState($order, $settings)['code']);

        $sent = new AbandonedOrderRecovery([
            'status'  => AbandonedOrderRecovery::STATUS_SENT,
            'sent_at' => now(),
        ]);
        $sent->forceFill(['created_at' => now()]);
        $order->setRelation('abandonedOrderRecoveries', new Collection([$sent]));
        self::assertSame('cooldown', $service->deliveryState($order, $settings)['code']);

        $sent->forceFill(['sent_at' => now()->subHours(60), 'suppressed_at' => now()]);
        self::assertSame('suppressed', $service->deliveryState($order, $settings)['code']);

        $sent->forceFill(['suppressed_at' => null]);
        $settings['max_attempts'] = 1;
        self::assertSame('maxed', $service->deliveryState($order, $settings)['code']);
    }

    public function test_recent_failed_delivery_has_a_duplicate_send_safety_window(): void
    {
        $service = new AbandonedOrderRecoveryService;
        $order   = $this->order();
        $failed  = new AbandonedOrderRecovery([
            'status'    => AbandonedOrderRecovery::STATUS_FAILED,
            'failed_at' => now(),
        ]);
        $failed->forceFill(['created_at' => now()]);
        $order->setRelation('abandonedOrderRecoveries', new Collection([$failed]));

        self::assertSame('retry_wait', $service->deliveryState($order, AbandonedOrderRecoveryService::defaults())['code']);
    }

    public function test_settings_are_bounded_and_header_newlines_are_removed(): void
    {
        $settings = (new AbandonedOrderRecoveryService)->normalizeSettings([
            'delay_hours'    => 0,
            'cooldown_hours' => 9999,
            'max_attempts'   => 99,
            'link_days'      => 0,
            'subject'        => "Subject\r\nBcc: victim@example.test",
        ]);

        self::assertSame(1, $settings['delay_hours']);
        self::assertSame(720, $settings['cooldown_hours']);
        self::assertSame(10, $settings['max_attempts']);
        self::assertSame(1, $settings['link_days']);
        self::assertStringNotContainsString("\n", $settings['subject']);
        self::assertStringNotContainsString("\r", $settings['subject']);
    }

    public function test_recovery_signature_survives_a_tenant_primary_domain_change(): void
    {
        $token = str_repeat('a', 64);
        $url   = (new AbandonedOrderRecoveryService)->signedRecoveryUrl($token, now()->addDay());
        $path  = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

        self::assertTrue(URL::hasValidSignature(Request::create('https://shop.example.test' . $path), absolute: false));
        self::assertTrue(URL::hasValidSignature(Request::create('https://new-domain.example' . $path), absolute: false));
        self::assertFalse(URL::hasValidSignature(Request::create(str_replace($token, str_repeat('b', 64), 'https://new-domain.example' . $path)), absolute: false));
    }

    public function test_recovery_contract_uses_hashed_tokens_post_mutations_and_escaped_template_text(): void
    {
        $root            = dirname(__DIR__, 2);
        $migration       = file_get_contents($root . '/database/migrations/2026_10_14_000000_create_abandoned_order_recoveries_table.php');
        $routes          = file_get_contents($root . '/beike/Shop/Routes/shop.php');
        $mail            = file_get_contents($root . '/themes/default/mails/abandoned_order_recovery.blade.php');
        $checkout        = file_get_contents($root . '/beike/Shop/Services/CheckoutService.php');
        $sidebar         = file_get_contents($root . '/beike/Admin/View/Components/Sidebar.php');
        $service         = file_get_contents($root . '/beike/Services/AbandonedOrderRecoveryService.php');
        $adminController = file_get_contents($root . '/beike/Admin/Http/Controllers/AbandonedOrderRecoveryController.php');
        $adminView       = file_get_contents($root . '/resources/beike/admin/views/pages/abandoned_order_recoveries/index.blade.php');

        self::assertStringContainsString("char('access_token_hash', 64)->unique('uq_recoveries_access_token_hash')", $migration);
        self::assertStringNotContainsString("string('access_token'", $migration);
        self::assertStringContainsString("middleware(['signed:relative', 'throttle:60,1'])", $routes);
        self::assertStringContainsString("Route::post('order-recovery/{token}/restore'", $routes);
        self::assertStringContainsString("nl2br(e(\$template['message']))", $mail);
        self::assertStringContainsString('markConverted($this->cart, $order)', $checkout);
        self::assertStringContainsString("'route' => 'order_recoveries.index'", $sidebar);
        self::assertStringContainsString("where('status', AbandonedOrderRecovery::STATUS_SENDING)", $service);
        self::assertStringContainsString("where('created_at', '<=', \$retryBefore)", $service);
        self::assertStringContainsString("whereHas('orderProducts')->latest('id')->value('id')", $adminController);
        self::assertStringContainsString('URL.createObjectURL(new Blob([html]', $adminView);
        self::assertStringContainsString(':src="previewUrl"', $adminView);
    }

    private function order(): Order
    {
        $order = new Order([
            'number'        => 'R-1001',
            'customer_name' => 'Example Customer',
            'email'         => 'customer@example.test',
            'status'        => StateMachineService::UNPAID,
        ]);
        $order->id = 1001;
        $order->forceFill(['created_at' => now()->subHours(30)]);
        $order->setRelation('orderProducts', new Collection([new OrderProduct(['quantity' => 1])]));
        $order->setRelation('abandonedOrderRecoveries', new Collection);

        return $order;
    }
}
