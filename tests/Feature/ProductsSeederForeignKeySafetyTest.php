<?php

namespace Tests\Feature;

use Database\Seeders\ProductsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class ProductsSeederForeignKeySafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config([
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
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_reseeding_preserves_tenant_products_and_plugin_foreign_key_rows(): void
    {
        $seeder = new ProductsSeeder;
        $seeder->run();
        $seedProductId = (int) DB::table('products')->min('id');
        self::assertGreaterThan(0, $seedProductId);

        Schema::create('longos_product_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
        DB::table('longos_product_reviews')->insert(['product_id' => $seedProductId]);
        DB::table('products')->insert([
            'id'           => 900001,
            'brand_id'     => 0,
            'images'       => '[]',
            'price'        => 19.99,
            'video'        => '',
            'position'     => 0,
            'active'       => 1,
            'variables'    => '[]',
            'tax_class_id' => 0,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $seeder->run();

        self::assertTrue(DB::table('products')->where('id', 900001)->exists(), 'Tenant products must survive a seed retry.');
        self::assertSame(1, DB::table('longos_product_reviews')->where('product_id', $seedProductId)->count());
        self::assertSame(1, DB::table('product_categories')->where('product_id', 2)->where('category_id', 100002)->count());
        self::assertSame(1, DB::table('product_relations')->where('product_id', 1)->where('relation_id', 2)->count());

        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/database/seeders/ProductsSeeder.php');
        self::assertStringNotContainsString('->truncate()', $source);
    }

    public function test_complete_database_seed_retry_is_a_safe_noop(): void
    {
        self::assertSame(0, Artisan::call('db:seed', [
            '--database'       => 'saas',
            '--force'          => true,
            '--no-interaction' => true,
        ]), Artisan::output());

        Schema::create('longos_product_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
        DB::table('longos_product_reviews')->insert(['product_id' => 1]);
        DB::table('brands')->insert([
            'id'         => 900001,
            'name'       => 'Merchant Brand',
            'first'      => 'M',
            'logo'       => '',
            'sort_order' => 999,
            'active'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertSame(0, Artisan::call('db:seed', [
            '--database'       => 'saas',
            '--force'          => true,
            '--no-interaction' => true,
        ]), Artisan::output());

        self::assertTrue(DB::table('brands')->where('id', 900001)->exists());
        self::assertTrue(DB::table('longos_product_reviews')->where('product_id', 1)->exists());
    }
}
