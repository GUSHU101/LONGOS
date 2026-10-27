<?php

namespace Tests\Feature;

use App\Saas\Models\PlatformPaymentSession;
use App\Saas\Models\Site;
use Beike\Exceptions\CartException;
use Beike\Models\CartProduct;
use Beike\Models\Order;
use Beike\Models\OrderProduct;
use Beike\Models\Product;
use Beike\Models\ProductSku;
use Beike\Services\StateMachineService;
use Beike\Shop\Services\OrderCartRestoreService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

class OrderCartRestoreFlowTest extends TestCase
{
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config([
            'app.locale'                => 'en',
            'cache.default'             => 'array',
            'database.default'          => 'saas',
            'database.connections.saas' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('saas');
        DB::setDefaultConnection('saas');
        self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]));

        $session = new Store('cart-restore-test', new ArraySessionHandler(120));
        $session->setId('cart-restore-session');
        $session->start();
        $request = Request::create('https://merchant.example.test/checkout/success', 'GET');
        $request->setLaravelSession($session);
        app()->instance('request', $request);
        app()->instance('session.store', $session);

        $this->site = Site::query()->create([
            'uuid'          => '11111111-1111-4111-8111-111111111111',
            'code'          => 'cart-restore',
            'name'          => 'Cart Restore',
            'database_name' => 'cart_restore',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'en',
            'timezone'      => 'UTC',
            'meta'          => [],
        ]);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_unbound_payment_failure_cancels_order_releases_stock_and_merges_cart_quantity(): void
    {
        [$order, $sku] = $this->orderWithReservedProduct();
        CartProduct::query()->create([
            'customer_id' => 0,
            'session_id'  => get_session_id(),
            'product_id'  => $sku->product_id,
            'product_sku' => $sku->sku,
            'quantity'    => 1,
            'selected'    => false,
        ]);
        $paymentSession = $this->paymentSession($order, PlatformPaymentSession::STATUS_PENDING);

        $result = app(OrderCartRestoreService::class)->restore($this->site, $order, null);

        self::assertSame(['restored_quantity' => 2, 'unavailable_quantity' => 0], $result);
        self::assertSame(StateMachineService::CANCELLED, $order->fresh()->status);
        self::assertFalse($order->fresh()->stock_reserved);
        self::assertSame(5, (int) $sku->fresh()->quantity);
        self::assertSame(3, (int) CartProduct::query()->where('product_sku', $sku->sku)->value('quantity'));
        self::assertTrue((bool) CartProduct::query()->where('product_sku', $sku->sku)->value('selected'));
        self::assertSame(PlatformPaymentSession::STATUS_CANCELLED, $paymentSession->fresh()->status);
        self::assertNull($paymentSession->fresh()->active_key);

        try {
            app(OrderCartRestoreService::class)->restore($this->site, $order->fresh(), null);
            self::fail('A restored order must not add its quantities twice.');
        } catch (CartException $exception) {
            self::assertSame(trans('shop/checkout.cart_restore_unavailable'), $exception->getMessage());
        }
        self::assertSame(3, (int) CartProduct::query()->where('product_sku', $sku->sku)->value('quantity'));
        self::assertSame(5, (int) $sku->fresh()->quantity);
    }

    public function test_provider_bound_processing_payment_is_never_duplicated_into_cart(): void
    {
        [$order, $sku]  = $this->orderWithReservedProduct();
        $paymentSession = $this->paymentSession($order, PlatformPaymentSession::STATUS_PROCESSING, [
            'provider'          => 'paypal',
            'provider_order_id' => 'PAYPAL-ORDER-123',
        ]);

        try {
            app(OrderCartRestoreService::class)->restore($this->site, $order, null);
            self::fail('A provider-bound payment must block cart restoration.');
        } catch (CartException $exception) {
            self::assertSame(trans('shop/checkout.cart_restore_pending'), $exception->getMessage());
        }

        self::assertSame(StateMachineService::UNPAID, $order->fresh()->status);
        self::assertTrue($order->fresh()->stock_reserved);
        self::assertSame(3, (int) $sku->fresh()->quantity);
        self::assertFalse(CartProduct::query()->exists());
        self::assertSame(PlatformPaymentSession::STATUS_PROCESSING, $paymentSession->fresh()->status);
    }

    /** @return array{Order, ProductSku} */
    private function orderWithReservedProduct(): array
    {
        $product = Product::query()->forceCreate([
            'brand_id'     => 0,
            'images'       => [],
            'price'        => 12.50,
            'video'        => '',
            'position'     => 0,
            'active'       => true,
            'variables'    => [],
            'tax_class_id' => 0,
            'shipping'     => true,
        ]);
        $sku = ProductSku::query()->forceCreate([
            'product_id'   => $product->id,
            'variants'     => [],
            'position'     => 0,
            'images'       => [],
            'model'        => 'RESTORE-MODEL',
            'sku'          => 'RESTORE-SKU',
            'price'        => 12.50,
            'origin_price' => 12.50,
            'cost_price'   => 8,
            'weight'       => 0,
            'quantity'     => 3,
            'is_default'   => true,
            'active'       => true,
        ]);
        $order = Order::query()->forceCreate([
            'number'                    => '202610270001',
            'customer_id'               => 0,
            'customer_group_id'         => 0,
            'shipping_address_id'       => 0,
            'payment_address_id'        => 0,
            'customer_name'             => 'Guest',
            'email'                     => 'guest@example.test',
            'calling_code'              => 1,
            'telephone'                 => '5550001',
            'total'                     => 25,
            'locale'                    => 'en',
            'currency_code'             => 'USD',
            'currency_value'            => '1',
            'ip'                        => '127.0.0.1',
            'user_agent'                => 'PHPUnit',
            'comment'                   => '',
            'status'                    => StateMachineService::UNPAID,
            'stock_reserved'            => true,
            'shipping_method_code'      => '',
            'shipping_method_name'      => '',
            'shipping_customer_name'    => 'Guest',
            'shipping_calling_code'     => '1',
            'shipping_telephone'        => '5550001',
            'shipping_country'          => 'United States',
            'shipping_country_id'       => 1,
            'shipping_zone'             => 'CA',
            'shipping_zone_id'          => 1,
            'shipping_city'             => 'Test',
            'shipping_address_1'        => '1 Test Street',
            'shipping_address_2'        => '',
            'shipping_zipcode'          => '90001',
            'payment_method_code'       => 'longos_platform_pay',
            'payment_method_name'       => 'LONGOS Platform Pay',
            'payment_customer_name'     => 'Guest',
            'payment_calling_code'      => '1',
            'payment_telephone'         => '5550001',
            'payment_country'           => 'United States',
            'payment_country_id'        => 1,
            'payment_zone'              => 'CA',
            'payment_zone_id'           => 1,
            'payment_city'              => 'Test',
            'payment_address_1'         => '1 Test Street',
            'payment_address_2'         => '',
            'payment_zipcode'           => '90001',
        ]);
        OrderProduct::query()->create([
            'order_id'     => $order->id,
            'product_id'   => $product->id,
            'order_number' => $order->number,
            'product_sku'  => $sku->sku,
            'name'         => 'Restore Product',
            'image'        => '',
            'quantity'     => 2,
            'price'        => 12.50,
        ]);

        return [$order, $sku];
    }

    /** @param array<string, mixed> $overrides */
    private function paymentSession(Order $order, string $status, array $overrides = []): PlatformPaymentSession
    {
        return PlatformPaymentSession::query()->create(array_merge([
            'uuid'         => '22222222-2222-4222-8222-222222222222',
            'token_hash'   => str_repeat('a', 64),
            'active_key'   => hash('sha256', $this->site->id . ':' . $order->id),
            'site_id'      => $this->site->id,
            'order_id'     => $order->id,
            'order_number' => $order->number,
            'amount'       => '25.00000000',
            'currency'     => 'USD',
            'locale'       => 'en',
            'status'       => $status,
            'expires_at'   => now('UTC')->addHour(),
        ], $overrides));
    }
}
