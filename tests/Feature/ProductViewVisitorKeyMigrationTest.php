<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class ProductViewVisitorKeyMigrationTest extends TestCase
{
    private string $databasePath;

    private string $previousConnection;

    private string $previousAppKey;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $this->previousConnection = (string) Config::get('database.default');
        $this->previousAppKey     = (string) Config::get('app.key', '');
        $this->databasePath       = tempnam(sys_get_temp_dir(), 'longos-product-view-migration-');
        Config::set('database.connections.product_view_migration', [
            'driver'                  => 'sqlite',
            'database'                => $this->databasePath,
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('database.default', 'product_view_migration');
        Config::set('app.key', 'dashboard-migration-test-key');
        DB::purge('product_view_migration');
        DB::setDefaultConnection('product_view_migration');

        Schema::create('product_views', function (Blueprint $table): void {
            $table->id();
            $table->string('session_id')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('product_view_migration');
        DB::setDefaultConnection($this->previousConnection);
        Config::set('database.default', $this->previousConnection);
        Config::set('app.key', $this->previousAppKey);
        @unlink($this->databasePath);
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_migration_backfills_the_shared_one_way_visitor_key(): void
    {
        DB::table('product_views')->insert([
            ['session_id' => 'existing-guest-session', 'created_at' => now(), 'updated_at' => now()],
            ['session_id' => '', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration = require database_path('migrations/2026_10_23_000000_add_visitor_key_to_product_views_table.php');
        $migration->up();

        self::assertTrue(Schema::hasColumn('product_views', 'visitor_key'));
        self::assertTrue(Schema::hasIndex('product_views', 'product_views_created_visitor_key_index'));
        self::assertSame(
            hash_hmac('sha256', 'existing-guest-session', 'dashboard-migration-test-key'),
            DB::table('product_views')->where('id', 1)->value('visitor_key'),
        );
        self::assertNull(DB::table('product_views')->where('id', 2)->value('visitor_key'));

        $migration->down();
        self::assertFalse(Schema::hasColumn('product_views', 'visitor_key'));
    }
}
