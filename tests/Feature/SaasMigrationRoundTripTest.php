<?php

namespace Tests\Feature;

use App\Saas\Support\DatabaseReadiness;
use Beike\Exceptions\CartException;
use Beike\Models\Order;
use Beike\Models\OrderProduct;
use Beike\Models\ProductSku;
use Beike\Repositories\OrderPaymentRepo;
use Beike\Services\StateMachineService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class SaasMigrationRoundTripTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $connection = [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ];
        config([
            'database.default'          => 'saas',
            'database.connections.saas' => $connection,
        ]);
        DB::purge('saas');
        DB::setDefaultConnection('saas');
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_latest_control_plane_migrations_round_trip(): void
    {
        self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]));
        self::assertTrue(Schema::hasColumn('saas_domains', 'routing_behavior'));
        self::assertTrue(Schema::hasColumn('saas_domains', 'published_at'));
        self::assertTrue(Schema::hasColumn('saas_domains', 'publication_error'));
        self::assertTrue(Schema::hasTable('saas_mail_sender_domains'));
        self::assertTrue(Schema::hasTable('saas_idempotency_records'));
        self::assertTrue(Schema::hasTable('saas_platform_payment_sessions'));
        self::assertTrue(Schema::hasColumn('saas_platform_payment_sessions', 'token_hash'));
        self::assertTrue(Schema::hasColumn('saas_platform_payment_sessions', 'locale'));
        self::assertTrue(Schema::hasColumn('saas_sites', 'provisioning_token'));
        self::assertTrue(Schema::hasTable('cache'));
        self::assertTrue(Schema::hasTable('cache_locks'));
        self::assertTrue(Schema::hasTable('storefront_visits'));
        self::assertTrue(Schema::hasColumn('storefront_visits', 'human_confirmed_at'));
        self::assertTrue(Schema::hasColumn('storefront_visits', 'confirmation_method'));
        self::assertTrue(Schema::hasIndex('storefront_visits', 'storefront_visits_confirmed_bucket_index'));
        self::assertTrue(Schema::hasIndex('storefront_visits', 'idx_storefront_visits_first_touch'));
        self::assertTrue(Schema::hasIndex('storefront_visits', 'idx_storefront_visits_latest_country'));
        self::assertTrue(Schema::hasIndex('order_histories', 'idx_order_histories_paid_first_transition'));
        self::assertTrue(Schema::hasTable('permissions'));
        self::assertTrue(Schema::hasTable('roles'));
        self::assertTrue(Schema::hasIndex('orders', 'orders_number_unique'));
        self::assertTrue(Schema::hasColumn('orders', 'stock_reserved'));
        self::assertTrue(Schema::hasIndex('order_payments', 'order_payments_order_id_unique'));
        self::assertTrue(Schema::hasTable('abandoned_order_recoveries'));
        self::assertTrue(Schema::hasColumn('mail_logs', 'delivery_id'));
        self::assertTrue(Schema::hasColumn('mail_logs', 'attempts'));
        self::assertTrue(Schema::hasColumn('mail_logs', 'last_attempt_at'));
        self::assertTrue(Schema::hasIndex('product_categories', 'product_categories_product_category_unique'));
        self::assertTrue(Schema::hasColumn('categories', 'auto_assign_enabled'));
        self::assertTrue(Schema::hasColumn('categories', 'auto_assign_keywords'));
        self::assertTrue(Schema::hasColumn('categories', 'auto_assign_brand_ids'));
        DatabaseReadiness::assertMigrated('saas');

        $roundTripSteps = count(array_filter(
            glob(database_path('migrations/*.php')) ?: [],
            static fn (string $path): bool => basename($path) >= '2026_10_03_000200_add_saas_domain_routing.php',
        ));
        self::assertGreaterThan(0, $roundTripSteps);
        self::assertSame(0, Artisan::call('migrate:rollback', [
            '--force'          => true,
            '--no-interaction' => true,
            '--step'           => $roundTripSteps,
        ]));
        self::assertFalse(Schema::hasColumn('saas_domains', 'routing_behavior'));
        self::assertFalse(Schema::hasColumn('saas_domains', 'published_at'));
        self::assertFalse(Schema::hasColumn('saas_domains', 'publication_error'));
        self::assertFalse(Schema::hasTable('saas_mail_sender_domains'));
        self::assertFalse(Schema::hasTable('saas_idempotency_records'));
        self::assertFalse(Schema::hasTable('saas_platform_payment_sessions'));
        self::assertFalse(Schema::hasColumn('saas_sites', 'provisioning_token'));
        self::assertFalse(Schema::hasTable('cache'));
        self::assertFalse(Schema::hasTable('cache_locks'));
        self::assertFalse(Schema::hasTable('storefront_visits'));
        self::assertFalse(Schema::hasIndex('order_histories', 'idx_order_histories_paid_first_transition'));
        self::assertFalse(Schema::hasIndex('orders', 'orders_number_unique'));
        self::assertFalse(Schema::hasColumn('orders', 'stock_reserved'));
        self::assertFalse(Schema::hasIndex('order_payments', 'order_payments_order_id_unique'));
        self::assertFalse(Schema::hasTable('abandoned_order_recoveries'));
        self::assertFalse(Schema::hasColumn('mail_logs', 'delivery_id'));
        self::assertFalse(Schema::hasColumn('mail_logs', 'attempts'));
        self::assertFalse(Schema::hasColumn('mail_logs', 'last_attempt_at'));
        self::assertFalse(Schema::hasIndex('product_categories', 'product_categories_product_category_unique'));
        self::assertFalse(Schema::hasColumn('categories', 'auto_assign_enabled'));
        self::assertFalse(Schema::hasColumn('categories', 'auto_assign_keywords'));
        self::assertFalse(Schema::hasColumn('categories', 'auto_assign_brand_ids'));

        DB::table('order_payments')->insert([
            [
                'order_id'   => 999,
                'request'    => json_encode(['attempt' => 1], JSON_THROW_ON_ERROR),
                'response'   => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'order_id'   => 999,
                'request'    => null,
                'response'   => json_encode(['status' => 'paid'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('product_categories')->insert([
            [
                'product_id'  => 999_999,
                'category_id' => 999_999,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'product_id'  => 999_999,
                'category_id' => 999_999,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);

        self::assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
        self::assertTrue(Schema::hasColumn('saas_domains', 'routing_behavior'));
        self::assertTrue(Schema::hasColumn('saas_domains', 'published_at'));
        self::assertTrue(Schema::hasColumn('saas_domains', 'publication_error'));
        self::assertTrue(Schema::hasTable('saas_mail_sender_domains'));
        self::assertTrue(Schema::hasTable('saas_idempotency_records'));
        self::assertTrue(Schema::hasTable('saas_platform_payment_sessions'));
        self::assertTrue(Schema::hasColumn('saas_sites', 'provisioning_token'));
        self::assertTrue(Schema::hasTable('cache'));
        self::assertTrue(Schema::hasTable('cache_locks'));
        self::assertTrue(Schema::hasTable('storefront_visits'));
        self::assertTrue(Schema::hasColumn('storefront_visits', 'human_confirmed_at'));
        self::assertTrue(Schema::hasColumn('storefront_visits', 'confirmation_method'));
        self::assertTrue(Schema::hasIndex('storefront_visits', 'storefront_visits_confirmed_bucket_index'));
        self::assertTrue(Schema::hasIndex('storefront_visits', 'idx_storefront_visits_first_touch'));
        self::assertTrue(Schema::hasIndex('storefront_visits', 'idx_storefront_visits_latest_country'));
        self::assertTrue(Schema::hasIndex('order_histories', 'idx_order_histories_paid_first_transition'));
        self::assertTrue(Schema::hasIndex('orders', 'orders_number_unique'));
        self::assertTrue(Schema::hasColumn('orders', 'stock_reserved'));
        self::assertTrue(Schema::hasIndex('order_payments', 'order_payments_order_id_unique'));
        self::assertTrue(Schema::hasTable('abandoned_order_recoveries'));
        self::assertTrue(Schema::hasColumn('mail_logs', 'delivery_id'));
        self::assertTrue(Schema::hasColumn('mail_logs', 'attempts'));
        self::assertTrue(Schema::hasColumn('mail_logs', 'last_attempt_at'));
        self::assertTrue(Schema::hasIndex('product_categories', 'product_categories_product_category_unique'));
        self::assertTrue(Schema::hasColumn('categories', 'auto_assign_enabled'));
        self::assertTrue(Schema::hasColumn('categories', 'auto_assign_keywords'));
        self::assertTrue(Schema::hasColumn('categories', 'auto_assign_brand_ids'));
        self::assertSame(1, DB::table('product_categories')
            ->where('product_id', 999_999)
            ->where('category_id', 999_999)
            ->count());
        DB::table('product_categories')->where('product_id', 999_999)->delete();
        self::assertSame(1, DB::table('order_payments')->where('order_id', 999)->count());
        self::assertSame(
            ['attempt' => 1],
            json_decode((string) DB::table('order_payments')->where('order_id', 999)->value('request'), true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            ['status' => 'paid'],
            json_decode((string) DB::table('order_payments')->where('order_id', 999)->value('response'), true, 512, JSON_THROW_ON_ERROR),
        );
        DatabaseReadiness::assertMigrated('saas');

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            self::assertSame(0, Artisan::call('db:seed', [
                '--force'          => true,
                '--no-interaction' => true,
            ]), "Seeding attempt {$attempt} failed: " . Artisan::output());
        }

        DatabaseReadiness::assertSeeded('saas');
        self::assertGreaterThan(0, DB::connection('saas')->table('products')->count());
        self::assertTrue(DB::connection('saas')->table('plugins')->where('code', 'longos_platform_pay')->exists());
        self::assertSame('0', (string) DB::connection('saas')->table('settings')
            ->where('type', 'plugin')
            ->where('space', 'longos_platform_pay')
            ->where('name', 'status')
            ->value('value'));

        $sku = ProductSku::query()->firstOrFail();
        $sku->update(['quantity' => 5]);

        $cancelledOrder = $this->createStockOrder($sku, 2, '261007120000000001');
        StateMachineService::getInstance($cancelledOrder)->changeStatus(StateMachineService::UNPAID, '', false);
        self::assertSame(3, (int) $sku->fresh()->quantity);
        self::assertTrue($cancelledOrder->fresh()->stock_reserved);

        StateMachineService::getInstance($cancelledOrder->fresh())->changeStatus(StateMachineService::CANCELLED, '', false);
        self::assertSame(5, (int) $sku->fresh()->quantity);
        self::assertFalse($cancelledOrder->fresh()->stock_reserved);

        $paidOrder = $this->createStockOrder($sku, 2, '261007120000000002');
        StateMachineService::getInstance($paidOrder)->changeStatus(StateMachineService::UNPAID, '', false);
        self::assertTrue(StateMachineService::getInstance($paidOrder->fresh())->markPaid('', false));
        self::assertFalse(StateMachineService::getInstance($paidOrder->fresh())->markPaid('', false));
        self::assertSame(3, (int) $sku->fresh()->quantity, 'Payment must not deduct stock a second time.');
        self::assertSame(StateMachineService::PAID, $paidOrder->fresh()->status);
        OrderPaymentRepo::createOrUpdatePayment($paidOrder->id, ['request' => ['attempt' => 1]]);
        OrderPaymentRepo::createOrUpdatePayment($paidOrder->id, ['response' => ['status' => 'paid']]);
        self::assertSame(1, DB::table('order_payments')->where('order_id', $paidOrder->id)->count());
        self::assertSame(
            ['attempt' => 1],
            json_decode((string) DB::table('order_payments')->where('order_id', $paidOrder->id)->value('request'), true, 512, JSON_THROW_ON_ERROR),
        );

        $outOfStockOrder = $this->createStockOrder($sku, 4, '261007120000000003');

        try {
            StateMachineService::getInstance($outOfStockOrder)->changeStatus(StateMachineService::UNPAID, '', false);
            self::fail('An order exceeding available inventory must be rejected.');
        } catch (CartException) {
            // Expected: inventory and order state are rolled back together.
        }
        self::assertSame(3, (int) $sku->fresh()->quantity);
        self::assertSame(StateMachineService::CREATED, $outOfStockOrder->fresh()->status);
        self::assertFalse($outOfStockOrder->fresh()->stock_reserved);
    }

    private function createStockOrder(ProductSku $sku, int $quantity, string $number): Order
    {
        $address = [
            'customer_name'   => 'Inventory Test',
            'calling_code'    => '86',
            'telephone'       => '13800000000',
            'country'         => 'China',
            'country_id'      => 1,
            'zone'            => 'Test Zone',
            'zone_id'         => 1,
            'city'            => 'Test City',
            'address_1'       => 'Test Address',
            'address_2'       => '',
            'zipcode'         => '000000',
        ];

        $order = Order::query()->create([
            'number'                    => $number,
            'customer_id'               => 0,
            'customer_group_id'         => 0,
            'shipping_address_id'       => 0,
            'payment_address_id'        => 0,
            'customer_name'             => $address['customer_name'],
            'email'                     => 'inventory@example.test',
            'calling_code'              => $address['calling_code'],
            'telephone'                 => $address['telephone'],
            'total'                     => 10,
            'locale'                    => 'en',
            'currency_code'             => 'USD',
            'currency_value'            => 1,
            'ip'                        => '127.0.0.1',
            'user_agent'                => 'PHPUnit',
            'comment'                   => '',
            'status'                    => StateMachineService::CREATED,
            'shipping_method_code'      => 'test_shipping',
            'shipping_method_name'      => 'Test Shipping',
            'shipping_customer_name'    => $address['customer_name'],
            'shipping_calling_code'     => $address['calling_code'],
            'shipping_telephone'        => $address['telephone'],
            'shipping_country'          => $address['country'],
            'shipping_country_id'       => $address['country_id'],
            'shipping_zone'             => $address['zone'],
            'shipping_zone_id'          => $address['zone_id'],
            'shipping_city'             => $address['city'],
            'shipping_address_1'        => $address['address_1'],
            'shipping_address_2'        => $address['address_2'],
            'shipping_zipcode'          => $address['zipcode'],
            'payment_method_code'       => 'test_payment',
            'payment_method_name'       => 'Test Payment',
            'payment_customer_name'     => $address['customer_name'],
            'payment_calling_code'      => $address['calling_code'],
            'payment_telephone'         => $address['telephone'],
            'payment_country'           => $address['country'],
            'payment_country_id'        => $address['country_id'],
            'payment_zone'              => $address['zone'],
            'payment_zone_id'           => $address['zone_id'],
            'payment_city'              => $address['city'],
            'payment_address_1'         => $address['address_1'],
            'payment_address_2'         => $address['address_2'],
            'payment_zipcode'           => $address['zipcode'],
        ]);

        OrderProduct::query()->create([
            'order_id'     => $order->id,
            'product_id'   => $sku->product_id,
            'order_number' => $order->number,
            'product_sku'  => $sku->sku,
            'name'         => 'Inventory Test Product',
            'image'        => '',
            'quantity'     => $quantity,
            'price'        => 10,
        ]);

        return $order;
    }
}
