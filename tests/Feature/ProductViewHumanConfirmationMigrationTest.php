<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class ProductViewHumanConfirmationMigrationTest extends TestCase
{
    private string $databasePath;

    private string $previousConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $this->previousConnection = (string) Config::get('database.default');
        $this->databasePath       = tempnam(sys_get_temp_dir(), 'longos-product-confirmation-');
        Config::set('database.connections.product_confirmation_migration', [
            'driver'                  => 'sqlite',
            'database'                => $this->databasePath,
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('database.default', 'product_confirmation_migration');
        DB::purge('product_confirmation_migration');
        DB::setDefaultConnection('product_confirmation_migration');

        Schema::create('product_views', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->char('visitor_key', 64)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->timestamps();
        });

        Schema::create('storefront_visits', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('bucket_at');
            $table->char('visitor_key', 64);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('human_confirmed_at')->nullable();
            $table->string('confirmation_method', 16)->nullable();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('product_confirmation_migration');
        DB::setDefaultConnection($this->previousConnection);
        Config::set('database.default', $this->previousConnection);
        @unlink($this->databasePath);
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_migration_keeps_only_product_views_linked_to_active_human_confirmation(): void
    {
        $now = now()->startOfMinute();

        DB::table('storefront_visits')->insert([
            [
                'bucket_at'           => $now->copy()->startOfHour(),
                'visitor_key'         => 'trusted-visitor',
                'first_seen_at'       => $now,
                'last_seen_at'        => $now,
                'human_confirmed_at'  => $now,
                'confirmation_method' => 'pointer',
            ],
            [
                'bucket_at'           => $now->copy()->startOfHour(),
                'visitor_key'         => 'passive-visitor',
                'first_seen_at'       => $now,
                'last_seen_at'        => $now,
                'human_confirmed_at'  => $now,
                'confirmation_method' => 'engaged',
            ],
        ]);
        DB::table('product_views')->insert([
            ['product_id' => 1, 'visitor_key' => 'trusted-visitor', 'country_code' => 'US', 'created_at' => $now, 'updated_at' => $now],
            ['product_id' => 2, 'visitor_key' => 'passive-visitor', 'country_code' => 'DE', 'created_at' => $now, 'updated_at' => $now],
            ['product_id' => 3, 'visitor_key' => 'unverified-visitor', 'country_code' => 'CA', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $migration = require database_path('migrations/2026_10_26_000000_require_human_confirmation_for_product_views.php');
        $migration->up();

        self::assertTrue(Schema::hasColumn('product_views', 'human_confirmed_at'));
        self::assertTrue(Schema::hasIndex('product_views', 'product_views_confirmed_created_index'));
        self::assertNotNull(DB::table('product_views')->where('product_id', 1)->value('human_confirmed_at'));
        self::assertSame('pointer', DB::table('product_views')->where('product_id', 1)->value('confirmation_method'));
        self::assertNull(DB::table('product_views')->where('product_id', 2)->value('human_confirmed_at'));
        self::assertNull(DB::table('product_views')->where('product_id', 3)->value('human_confirmed_at'));
        self::assertNull(DB::table('storefront_visits')->where('visitor_key', 'passive-visitor')->value('human_confirmed_at'));

        $migration->down();
        self::assertFalse(Schema::hasColumn('product_views', 'human_confirmed_at'));
    }
}
