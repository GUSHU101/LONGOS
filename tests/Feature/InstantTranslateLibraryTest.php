<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Plugin\LongosInstantTranslate\Services\TranslationLibrary;

class InstantTranslateLibraryTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $this->databasePath = tempnam(sys_get_temp_dir(), 'lit-library-');
        config([
            'app.key'                               => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'database.default'                      => 'lit_library_test',
            'database.connections.lit_library_test' => [
                'driver'                  => 'sqlite', 'database' => $this->databasePath, 'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('lit_library_test');
        DB::setDefaultConnection('lit_library_test');
        $this->createLegacyFixture();
    }

    protected function tearDown(): void
    {
        DB::disconnect('lit_library_test');
        @unlink($this->databasePath);
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_migration_imports_usable_legacy_library_accounts_and_glossary_without_dropping_backup(): void
    {
        $ciphertext = Crypt::encryptString(json_encode([
            'api_key' => 'secret-key', 'model' => 'tencent/Hunyuan-MT-7B',
        ], JSON_THROW_ON_ERROR));
        DB::table('plugins')->insert(['type' => 'feature', 'code' => 'longos_global_translate', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('settings')->insert([
            ['type' => 'plugin', 'space' => 'longos_global_translate', 'name' => 'status', 'value' => '1', 'json' => false, 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'plugin', 'space' => 'longos_instant_translate', 'name' => 'status', 'value' => '0', 'json' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $entry = DB::table('lgt_entries')->insertGetId([
            'source_locale' => 'en', 'source_text' => 'Protect your phone', 'source_hash' => hash('sha256', 'legacy'),
            'kind'          => 'product', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('lgt_translations')->insert([
            ['entry_id' => $entry, 'locale' => 'fr', 'text' => 'Protégez votre téléphone', 'status' => 'approved', 'provider' => 'deepl', 'quality_code' => null, 'created_at' => now(), 'updated_at' => now()],
            ['entry_id' => $entry, 'locale' => 'de', 'text' => 'Schützen Sie Ihr Telefon', 'status' => 'needs_review', 'provider' => 'deepl', 'quality_code' => 'SOURCE_ECHO', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('lgt_provider_accounts')->insert([
            'provider'               => 'siliconflow', 'billing_identity_hash' => hash('sha256', 'account'),
            'account_tier'           => 'Legacy SiliconFlow', 'credentials_ciphertext' => $ciphertext,
            'credential_fingerprint' => hash('sha256', 'credentials'), 'credential_revision' => 2,
            'soft_cap'               => 0, 'qps_limit' => 1, 'failover_priority' => 10, 'last_dispatch_at_us' => 0,
            'enabled'                => true, 'dispatch_enabled' => true, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('lgt_glossary_terms')->insert([
            'source_locale' => 'en', 'target_locale' => 'fr', 'term' => 'LONGOS', 'replacement' => 'LONGOS',
            'protect'       => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        (require base_path('plugins/LongosInstantTranslate/Migrations/2026_11_10_120000_create_instant_translation_library.php'))->up();

        self::assertSame('ready', DB::table('lit_translation_entries')->where('target_locale', 'fr')->value('status'));
        self::assertSame('review', DB::table('lit_translation_entries')->where('target_locale', 'de')->value('status'));
        self::assertSame($ciphertext, DB::table('lit_provider_accounts')->value('credentials_ciphertext'));
        self::assertSame('LONGOS', DB::table('lit_glossary_terms')->value('term'));
        self::assertTrue(Schema::hasTable('lgt_translations'), 'Legacy tables remain as the rollback backup.');
        self::assertFalse(DB::table('plugins')->where('code', 'longos_global_translate')->exists());
        self::assertSame('1', DB::table('settings')->where('space', 'longos_instant_translate')->where('name', 'status')->value('value'));

        $lookup = (new TranslationLibrary)->lookup('fr', [' Protect   your phone ']);
        self::assertSame('Protégez votre téléphone', $lookup['Protect your phone']);
    }

    private function createLegacyFixture(): void
    {
        Schema::create('plugins', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('code')->unique();
            $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('space');
            $table->string('name');
            $table->text('value')->nullable();
            $table->boolean('json')->default(false);
            $table->timestamps();
        });
        Schema::create('lgt_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('source_locale');
            $table->longText('source_text');
            $table->char('source_hash', 64);
            $table->string('kind', 32);
            $table->timestamps();
        });
        Schema::create('lgt_translations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('entry_id');
            $table->string('locale');
            $table->longText('text')->nullable();
            $table->string('status');
            $table->string('provider')->nullable();
            $table->string('quality_code')->nullable();
            $table->timestamps();
        });
        Schema::create('lgt_provider_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->char('billing_identity_hash', 64);
            $table->string('account_tier')->nullable();
            $table->text('credentials_ciphertext')->nullable();
            $table->char('credential_fingerprint', 64)->nullable();
            $table->unsignedBigInteger('credential_revision')->default(1);
            $table->unsignedBigInteger('soft_cap')->default(0);
            $table->unsignedSmallInteger('qps_limit')->default(1);
            $table->unsignedSmallInteger('failover_priority')->default(100);
            $table->unsignedBigInteger('last_dispatch_at_us')->default(0);
            $table->boolean('enabled')->default(false);
            $table->boolean('dispatch_enabled')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('lgt_glossary_terms', function (Blueprint $table): void {
            $table->id();
            $table->string('source_locale');
            $table->string('target_locale')->nullable();
            $table->string('term', 512);
            $table->string('replacement', 512)->nullable();
            $table->boolean('protect')->default(true);
            $table->timestamps();
        });
    }
}
