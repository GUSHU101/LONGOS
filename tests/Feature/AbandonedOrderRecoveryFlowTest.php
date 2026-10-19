<?php

namespace Tests\Feature;

use Beike\Mail\AbandonedOrderRecoveryMail;
use Beike\Models\AbandonedOrderRecovery;
use Beike\Models\Discount;
use Beike\Models\Order;
use Beike\Models\OrderProduct;
use Beike\Models\Product;
use Beike\Models\ProductSku;
use Beike\Repositories\CartRepo;
use Beike\Repositories\PluginRepo;
use Beike\Services\AbandonedOrderRecoveryService;
use Beike\Services\StateMachineService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AbandonedOrderRecoveryFlowTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $this->databasePath = tempnam(sys_get_temp_dir(), 'longos-recovery-');
        config([
            'database.default'                    => 'recovery_test',
            'database.connections.recovery_test'  => [
                'driver'                  => 'sqlite',
                'database'                => $this->databasePath,
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('recovery_test');
        DB::setDefaultConnection('recovery_test');
        self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]));

        $request = Request::create('/');
        $request->headers->set('Bksessionid', 'recovery-flow-session');
        $app->instance('request', $request);
        PluginRepo::resetRuntimeCache();
    }

    protected function tearDown(): void
    {
        PluginRepo::resetRuntimeCache();
        DB::disconnect('recovery_test');
        @unlink($this->databasePath);
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_restore_releases_original_reservation_applies_discount_and_tracks_conversion(): void
    {
        $product = Product::query()->create([
            'brand_id'     => 0,
            'images'       => [],
            'video'        => '',
            'position'     => 0,
            'active'       => true,
            'variables'    => [],
            'tax_class_id' => 0,
            'shipping'     => false,
        ]);
        $sku = ProductSku::query()->create([
            'product_id'   => $product->id,
            'variants'     => [],
            'images'       => [],
            'model'        => 'RECOVERY',
            'sku'          => 'RECOVERY-SKU',
            'price'        => 20,
            'origin_price' => 20,
            'cost_price'   => 10,
            'quantity'     => 5,
            'is_default'   => true,
            'active'       => true,
        ]);
        $order = $this->createOrder($sku, 2, 'RECOVERY-ORIGINAL');
        StateMachineService::getInstance($order)->changeStatus(StateMachineService::UNPAID, '', false);
        self::assertSame(3, (int) $sku->fresh()->quantity);
        self::assertTrue($order->fresh()->stock_reserved);

        $discount = Discount::query()->create([
            'name'           => 'Recovery 10%',
            'mode'           => 'code',
            'code'           => 'COME-BACK-10',
            'type'           => 'order_amount',
            'value_type'     => 'percentage',
            'value'          => 10,
            'minimum_amount' => 0,
            'status'         => true,
        ]);
        $recovery = AbandonedOrderRecovery::query()->create([
            'order_id'          => $order->id,
            'discount_id'       => $discount->id,
            'recipient_email'   => $order->email,
            'delivery_id'       => 'recovery:test-flow',
            'access_token_hash' => hash('sha256', str_repeat('a', 64)),
            'status'            => AbandonedOrderRecovery::STATUS_SENT,
            'sender_category'   => 'marketing',
            'subject'           => 'Complete your order',
            'body'              => 'Body',
            'discount_code'     => $discount->code,
            'sent_at'           => now(),
            'expires_at'        => now()->addDay(),
        ]);

        $service = new AbandonedOrderRecoveryService;
        $result  = $service->restoreToCart($recovery->load('order'));

        self::assertSame(['restored' => 1, 'unavailable' => 0, 'discount_applied' => true], $result);
        self::assertSame(StateMachineService::CANCELLED, $order->fresh()->status);
        self::assertFalse($order->fresh()->stock_reserved);
        self::assertSame(5, (int) $sku->fresh()->quantity, 'Cancelling the original must release its reservation before rebuilding the cart.');

        $cart = CartRepo::createCart(null);
        self::assertSame('COME-BACK-10', $cart->extra['discount_code']);
        self::assertSame($recovery->id, $cart->extra[AbandonedOrderRecoveryService::CART_RECOVERY_KEY]);
        self::assertSame(2, (int) CartRepo::selectedCartProducts(0)->firstOrFail()->quantity);
        self::assertNotNull($recovery->fresh()->restored_at);

        $convertedOrder = $this->createOrder($sku, 2, 'RECOVERY-CONVERTED');
        $service->markConverted($cart, $convertedOrder);
        self::assertSame($convertedOrder->id, $recovery->fresh()->converted_order_id);
        self::assertNotNull($recovery->fresh()->converted_at);

        $mailOrder = $this->createOrder($sku, 1, 'RECOVERY-MAIL');
        StateMachineService::getInstance($mailOrder)->changeStatus(StateMachineService::UNPAID, '', false);
        $mailOrder->forceFill([
            'created_at'    => now()->subHours(30),
            'customer_name' => '<script>alert(1)</script>',
        ])->save();
        Mail::fake();

        $delivery = $service->send($mailOrder->fresh(), $discount->id, null);

        self::assertSame(AbandonedOrderRecovery::STATUS_SENT, $delivery->status);
        self::assertSame('marketing', $delivery->sender_category);
        self::assertSame($discount->code, $delivery->discount_code);
        self::assertSame(64, strlen($delivery->access_token_hash));
        Mail::assertSent(AbandonedOrderRecoveryMail::class, 1);

        $preview = $service->preview($mailOrder->fresh(), $discount->id, $service->settings());
        self::assertStringContainsString('COME-BACK-10', $preview);
        self::assertStringNotContainsString('<script>alert(1)</script>', $preview);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $preview);

        $retryOrder = $this->createOrder($sku, 1, 'RECOVERY-RETRY');
        StateMachineService::getInstance($retryOrder)->changeStatus(StateMachineService::UNPAID, '', false);
        $retryOrder->forceFill(['created_at' => now()->subHours(30)])->save();
        $retryDeliveryId = 'recovery:' . $retryOrder->id . ':retry-test';
        $tokenMethod     = new ReflectionMethod($service, 'tokenForDeliveryId');
        $retryToken      = $tokenMethod->invoke($service, $retryDeliveryId);
        $failedDelivery  = AbandonedOrderRecovery::query()->create([
            'order_id'          => $retryOrder->id,
            'discount_id'       => $discount->id,
            'recipient_email'   => $retryOrder->email,
            'delivery_id'       => $retryDeliveryId,
            'access_token_hash' => hash('sha256', $retryToken),
            'status'            => AbandonedOrderRecovery::STATUS_FAILED,
            'sender_category'   => 'marketing',
            'subject'           => 'Stable retry subject',
            'body'              => 'Stable retry body',
            'discount_code'     => $discount->code,
            'failed_at'         => now()->subMinutes(20),
            'expires_at'        => now()->addDay(),
            'metadata'          => ['heading' => 'Stable heading', 'button_label' => 'Return', 'discount_message' => 'Use the code'],
        ]);

        $retried = $service->send($retryOrder->fresh(), $discount->id, null);
        self::assertSame($failedDelivery->id, $retried->id);
        self::assertSame($retryDeliveryId, $retried->delivery_id);
        self::assertSame(2, $retried->transport_attempts);
        self::assertSame('Stable retry subject', $retried->subject);
        Mail::assertSent(AbandonedOrderRecoveryMail::class, 2);
    }

    private function createOrder(ProductSku $sku, int $quantity, string $number): Order
    {
        $address = [
            'customer_name' => 'Recovery Customer', 'calling_code' => '86', 'telephone' => '13800000000',
            'country'       => 'China', 'country_id' => 1, 'zone' => 'Zone', 'zone_id' => 1,
            'city'          => 'City', 'address_1' => 'Address', 'address_2' => '', 'zipcode' => '000000',
        ];
        $order = Order::query()->create([
            'number'                 => $number, 'customer_id' => 0, 'customer_group_id' => 0,
            'shipping_address_id'    => 0, 'payment_address_id' => 0,
            'customer_name'          => $address['customer_name'], 'email' => 'recovery@example.test',
            'calling_code'           => $address['calling_code'], 'telephone' => $address['telephone'],
            'total'                  => 40, 'locale' => 'en', 'currency_code' => 'USD', 'currency_value' => 1,
            'ip'                     => '127.0.0.1', 'user_agent' => 'PHPUnit', 'comment' => '',
            'status'                 => StateMachineService::CREATED, 'shipping_method_code' => 'test', 'shipping_method_name' => 'Test',
            'shipping_customer_name' => $address['customer_name'], 'shipping_calling_code' => $address['calling_code'],
            'shipping_telephone'     => $address['telephone'], 'shipping_country' => $address['country'],
            'shipping_country_id'    => $address['country_id'], 'shipping_zone' => $address['zone'],
            'shipping_zone_id'       => $address['zone_id'], 'shipping_city' => $address['city'],
            'shipping_address_1'     => $address['address_1'], 'shipping_address_2' => '', 'shipping_zipcode' => $address['zipcode'],
            'payment_method_code'    => 'test', 'payment_method_name' => 'Test',
            'payment_customer_name'  => $address['customer_name'], 'payment_calling_code' => $address['calling_code'],
            'payment_telephone'      => $address['telephone'], 'payment_country' => $address['country'],
            'payment_country_id'     => $address['country_id'], 'payment_zone' => $address['zone'],
            'payment_zone_id'        => $address['zone_id'], 'payment_city' => $address['city'],
            'payment_address_1'      => $address['address_1'], 'payment_address_2' => '', 'payment_zipcode' => $address['zipcode'],
        ]);
        OrderProduct::query()->create([
            'order_id'    => $order->id, 'product_id' => $sku->product_id, 'order_number' => $order->number,
            'product_sku' => $sku->sku, 'name' => 'Recovery Product', 'image' => '',
            'quantity'    => $quantity, 'price' => 20,
        ]);

        return $order;
    }
}
