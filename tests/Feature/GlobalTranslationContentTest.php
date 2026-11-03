<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Plugin\LongosGlobalTranslate\Services\ContentScanner;

class GlobalTranslationContentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config(['database.connections.lgt_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'tenant_a_']]);
        DB::purge('lgt_test');
        DB::setDefaultConnection('lgt_test');
        $migration = require base_path('plugins/LongosGlobalTranslate/Migrations/2026_11_02_000000_create_translation_tables.php');
        $migration->up();
        $migration->up();
        $upgrade = require base_path('plugins/LongosGlobalTranslate/Migrations/2026_11_02_000001_upgrade_translation_schema.php');
        $upgrade->up();
        $claims = require base_path('plugins/LongosGlobalTranslate/Migrations/2026_11_02_000002_add_worker_claim_tokens.php');
        $claims->up();
        $rateLimit = require base_path('plugins/LongosGlobalTranslate/Migrations/2026_11_03_000004_add_provider_dispatch_rate_limit.php');
        $rateLimit->up();
        Schema::create('products', function (Blueprint $t): void {
            $t->id();
            $t->boolean('active');
        });
        Schema::create('product_descriptions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->string('locale');
            $t->string('name');
            $t->text('content');
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('lgt_test');
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_thousand_products_are_idempotent_and_private_content_is_excluded(): void
    {
        for ($id = 1; $id <= 1001; $id++) {
            DB::table('products')->insert(['id' => $id, 'active' => $id <= 1000]);
            DB::table('product_descriptions')->insert(['product_id' => $id, 'locale' => 'en', 'name' => 'Case ' . $id, 'content' => '<p>Original text</p>']);
        }
        $scanner = new ContentScanner;
        self::assertSame(1000, $scanner->scan()['found']);
        self::assertSame(0, $scanner->scan()['changed']);
        self::assertSame(1000, DB::table('lgt_entries')->count());
        self::assertFalse(DB::table('lgt_entries')->where('entity_id', 1001)->exists());
    }

    public function test_source_change_only_invalidates_its_field_and_retains_locked_text(): void
    {
        DB::table('products')->insert(['id' => 1, 'active' => true]);
        DB::table('product_descriptions')->insert(['product_id' => 1, 'locale' => 'en', 'name' => 'Case', 'content' => 'Body']);
        $scanner = new ContentScanner;
        $scanner->scan();
        foreach (DB::table('lgt_entries')->get() as $entry) {
            DB::table('lgt_translations')->insert(['entry_id' => $entry->id, 'locale' => 'fr', 'source_hash' => $entry->source_hash, 'text' => 'Human text', 'locked' => true, 'status' => 'approved']);
        }
        DB::table('product_descriptions')->where('product_id', 1)->update(['name' => 'New case']);
        self::assertSame(1, $scanner->scan()['changed']);
        self::assertSame(1, DB::table('lgt_translations')->where('status', 'stale')->count());
        self::assertSame(0, DB::table('lgt_translations')->where('status', 'approved')->count());
        self::assertSame(1, DB::table('lgt_translations')->where('locked', true)->where('text', 'Human text')->count());
    }
}
