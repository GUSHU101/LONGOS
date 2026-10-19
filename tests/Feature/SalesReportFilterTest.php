<?php

namespace Tests\Feature;

use Beike\Admin\Repositories\Report\OrderReportRepo;
use Beike\Repositories\OrderProductRepo;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class SalesReportFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config([
            'database.default'                 => 'report_test',
            'database.connections.report_test' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('report_test');
        DB::setDefaultConnection('report_test');

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('status');
            $table->decimal('total', 16, 4);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('order_products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('quantity');
            $table->decimal('price', 16, 4);
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('customers')->insert([
            ['id' => 1, 'name' => 'Customer One', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Customer Two', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('orders')->insert([
            ['id' => 1, 'customer_id' => 1, 'status' => 'paid', 'total' => 10, 'created_at' => '2026-10-10 12:00:00', 'updated_at' => now()],
            ['id' => 2, 'customer_id' => 1, 'status' => 'cancelled', 'total' => 100, 'created_at' => '2026-10-10 13:00:00', 'updated_at' => now()],
            ['id' => 3, 'customer_id' => 1, 'status' => 'paid', 'total' => 20, 'created_at' => '2026-10-11 00:00:00', 'updated_at' => now()],
            ['id' => 4, 'customer_id' => 2, 'status' => 'paid', 'total' => 5, 'created_at' => '2026-10-10 23:59:59', 'updated_at' => now()],
        ]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('report_test');
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_customer_ranking_honors_selected_status_and_inclusive_date_range(): void
    {
        $rows = OrderReportRepo::getSaleInfoByCustomers('order_amount', [
            'order_statuses' => ['paid'],
            'date_start'     => '2026-10-10',
            'date_end'       => '2026-10-10',
        ])->keyBy('customer_id');

        self::assertCount(2, $rows);
        self::assertSame(1, (int) $rows[1]->order_count);
        self::assertSame(10.0, (float) $rows[1]->order_amount);
        self::assertSame(1, (int) $rows[2]->order_count);
        self::assertSame(5.0, (float) $rows[2]->order_amount);
    }

    public function test_product_ranking_uses_the_same_inclusive_date_boundaries(): void
    {
        $query = OrderProductRepo::getBuilder([
            'date_start' => '2026-10-10',
            'date_end'   => '2026-10-10',
        ]);
        $bindings = array_map(
            static fn ($value): string => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value,
            $query->getBindings(),
        );

        self::assertContains('2026-10-10 00:00:00', $bindings);
        self::assertContains('2026-10-11 00:00:00', $bindings);
    }
}
