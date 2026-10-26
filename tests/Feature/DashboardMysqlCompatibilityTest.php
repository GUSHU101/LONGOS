<?php

namespace Tests\Feature;

use Beike\Admin\Services\DashboardService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class DashboardMysqlCompatibilityTest extends TestCase
{
    private const TABLES = [
        'order_products',
        'order_histories',
        'orders',
        'dashboard_events',
        'storefront_visits',
        'product_views',
    ];

    private string $previousConnection;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('DASHBOARD_MYSQL_TEST') !== '1') {
            self::markTestSkipped('Set DASHBOARD_MYSQL_TEST=1 to run the MySQL compatibility contract.');
        }

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $this->previousConnection = (string) Config::get('database.default');
        Config::set('database.connections.dashboard_mysql', [
            'driver'         => 'mysql',
            'host'           => getenv('DASHBOARD_MYSQL_HOST') ?: '127.0.0.1',
            'port'           => getenv('DASHBOARD_MYSQL_PORT') ?: '3306',
            'database'       => getenv('DASHBOARD_MYSQL_DATABASE') ?: 'longos_dashboard_test',
            'username'       => getenv('DASHBOARD_MYSQL_USERNAME') ?: 'longos',
            'password'       => getenv('DASHBOARD_MYSQL_PASSWORD') ?: 'longos-test',
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_unicode_ci',
            'prefix'         => 'tenant_',
            'prefix_indexes' => true,
            'strict'         => true,
            'engine'         => 'InnoDB',
        ]);
        Config::set('database.default', 'dashboard_mysql');
        Config::set('app.dashboard.enable_cache', false);
        DB::purge('dashboard_mysql');
        DB::setDefaultConnection('dashboard_mysql');
        Carbon::setTestNow(Carbon::parse('2026-10-23 12:00:00', config('app.timezone')));

        $this->dropSchema();
        $this->createSchema();
        $this->seedAnalytics();
    }

    protected function tearDown(): void
    {
        if (isset($this->previousConnection)) {
            $this->dropSchema();
            DB::disconnect('dashboard_mysql');
            DB::setDefaultConnection($this->previousConnection);
            Config::set('database.default', $this->previousConnection);
            Carbon::setTestNow();
            restore_error_handler();
            restore_exception_handler();
        }

        parent::tearDown();
    }

    public function test_complete_overview_runs_on_mysql_with_a_tenant_prefix(): void
    {
        $overview = (new DashboardService)->getOverview('today');

        self::assertSame(2, $overview['stats']['visitors']['current']);
        self::assertSame(2, $overview['meta']['tracked_visitors']);
        self::assertSame(2, array_sum(array_column($overview['sources'], 'value')));
        self::assertSame(2, array_sum(array_column($overview['countries'], 'visitors')));
        self::assertSame(2, array_sum($overview['trends']['uv']));
    }

    private function createSchema(): void
    {
        Schema::create('product_views', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('ip')->nullable();
            $table->string('session_id')->nullable();
            $table->char('visitor_key', 64)->nullable();
            $table->string('referer')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->timestamp('human_confirmed_at')->nullable();
            $table->string('confirmation_method', 16)->nullable();
            $table->timestamps();
        });
        Schema::create('storefront_visits', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('bucket_at');
            $table->char('visitor_key', 64);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('source_key', 32)->default('direct');
            $table->string('source_detail')->nullable();
            $table->string('referer', 1000)->nullable();
            $table->string('landing_path', 1000)->nullable();
            $table->unsignedInteger('page_views')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('human_confirmed_at')->nullable();
            $table->string('confirmation_method', 16)->nullable();
            $table->timestamps();
            $table->unique(['bucket_at', 'visitor_key']);
        });
        Schema::create('dashboard_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('session_id')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('ip')->nullable();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->default(0);
            $table->string('email')->default('');
            $table->string('ip')->default('');
            $table->string('status');
            $table->decimal('total', 16, 4)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('order_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('order_products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->decimal('price', 16, 4);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function seedAnalytics(): void
    {
        DB::table('storefront_visits')->insert([
            'bucket_at'           => '2026-10-23 09:00:00',
            'visitor_key'         => str_repeat('a', 64),
            'customer_id'         => null,
            'country_code'        => 'CN',
            'source_key'          => 'google',
            'source_detail'       => 'google.com',
            'referer'             => 'https://google.com/search',
            'landing_path'        => '/',
            'page_views'          => 2,
            'first_seen_at'       => '2026-10-23 09:00:00',
            'last_seen_at'        => '2026-10-23 09:05:00',
            'human_confirmed_at'  => '2026-10-23 09:01:00',
            'confirmation_method' => 'pointer',
            'created_at'          => '2026-10-23 09:00:00',
            'updated_at'          => '2026-10-23 09:05:00',
        ]);
        DB::table('product_views')->insert([
            [
                'product_id'         => 1, 'customer_id' => null, 'ip' => '192.0.2.1', 'session_id' => 'covered',
                'visitor_key'        => str_repeat('a', 64), 'referer' => 'https://google.com/search', 'country_code' => 'CN',
                'human_confirmed_at' => '2026-10-23 09:02:00', 'confirmation_method' => 'pointer',
                'created_at'         => '2026-10-23 09:02:00', 'updated_at' => '2026-10-23 09:02:00',
            ],
            [
                'product_id'         => 2, 'customer_id' => null, 'ip' => '192.0.2.2', 'session_id' => 'product-only',
                'visitor_key'        => str_repeat('b', 64), 'referer' => 'https://bing.com/search', 'country_code' => 'US',
                'human_confirmed_at' => '2026-10-23 10:02:00', 'confirmation_method' => 'scroll',
                'created_at'         => '2026-10-23 10:02:00', 'updated_at' => '2026-10-23 10:02:00',
            ],
        ]);
    }

    private function dropSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
}
