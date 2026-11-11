<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Plugin\LongosInstantTranslate\Providers\ProviderRegistry;
use Plugin\LongosInstantTranslate\Providers\TranslationProviderInterface;
use Plugin\LongosInstantTranslate\Providers\TranslationProviderRequest;
use Plugin\LongosInstantTranslate\Providers\TranslationProviderResponse;
use Plugin\LongosInstantTranslate\Services\ContentScanner;
use Plugin\LongosInstantTranslate\Services\LibraryBuilder;
use Plugin\LongosInstantTranslate\Services\ProviderAccountService;
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

    public function test_builder_does_not_consume_attempts_or_fail_entries_without_a_verified_provider(): void
    {
        $this->migrateLibrary();
        (new TranslationLibrary)->queue('Lightweight travel bag', 'product', ['fr']);
        $registry = new ProviderRegistry;
        $builder  = new LibraryBuilder($registry, new ProviderAccountService($registry));

        for ($attempt = 0; $attempt < 8; $attempt++) {
            self::assertSame('no_provider', $builder->processOne(true)['state']);
        }

        $entry = DB::table('lit_translation_entries')->first();
        self::assertSame('pending', $entry->status);
        self::assertSame(0, (int) $entry->attempts);
        self::assertNull($entry->error_code);
    }

    public function test_library_queues_large_scans_in_bulk_without_overwriting_ready_translations(): void
    {
        $this->migrateLibrary();
        $library = new TranslationLibrary;
        $sources = [
            ['text' => 'Lightweight travel bag', 'kind' => 'product'],
            ['text' => 'Waterproof recycled fabric', 'kind' => 'product'],
        ];

        self::assertSame(4, $library->queueBatch($sources, ['fr', 'de']));
        DB::table('lit_translation_entries')->where('target_locale', 'fr')->where('source_text', 'Lightweight travel bag')->update([
            'status' => 'ready', 'translated_text' => 'Sac de voyage léger',
        ]);
        self::assertSame(0, $library->queueBatch($sources, ['fr', 'de']));
        self::assertSame(4, DB::table('lit_translation_entries')->count());
        self::assertSame('ready', DB::table('lit_translation_entries')->where('translated_text', 'Sac de voyage léger')->value('status'));
    }

    public function test_content_scan_uses_bounded_bulk_database_writes(): void
    {
        $this->migrateLibrary();
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $report = (new ContentScanner(new TranslationLibrary))->scan(['fr']);

        self::assertGreaterThan(0, $report['found']);
        self::assertSame($report['queued'], DB::table('lit_translation_entries')->count());
        self::assertLessThan(100, $queries, 'The scan must not return to per-text database writes.');
    }

    public function test_builder_translates_one_entry_and_commits_usage_with_a_verified_provider(): void
    {
        $this->migrateLibrary();
        (new TranslationLibrary)->queue('Lightweight travel bag', 'product', ['fr']);
        $provider = new class implements TranslationProviderInterface
        {
            public function id(): string
            {
                return 'siliconflow';
            }

            public function validateCredentialShape(array $credentials): void {}

            public function supports(string $sourceLocale, string $targetLocale): bool
            {
                return $sourceLocale === 'en' && $targetLocale === 'fr';
            }

            public function buildBatches(array $segments, string $sourceLocale, string $targetLocale): array
            {
                return [$segments];
            }

            public function send(TranslationProviderRequest $request, array $credentials): TranslationProviderResponse
            {
                return new TranslationProviderResponse(true, ['Sac de voyage léger'], 'test-trace', null, hash('sha256', 'ok'), 2);
            }

            public function classifyFailure(\Throwable|TranslationProviderResponse $failure): array
            {
                return ['kind' => 'definite_failure', 'code' => 'TEST_FAILURE', 'definitely_unbilled' => true];
            }
        };
        $registry  = new ProviderRegistry(['siliconflow' => $provider]);
        $accounts  = new ProviderAccountService($registry);
        $accountId = $accounts->save([
            'provider' => 'siliconflow', 'label' => 'test', 'enabled' => true,
            'priority' => 1, 'qps_limit' => 1, 'rpm_limit' => 60, 'tpm_limit' => 80000,
        ], ['api_key' => 'test-key', 'model' => 'test-model']);
        DB::table('lit_provider_accounts')->where('id', $accountId)->update([
            'verified_at' => now(), 'dispatch_enabled' => true,
        ]);

        $result = (new LibraryBuilder($registry, $accounts))->processOne(true);

        self::assertSame('ready', $result['state']);
        $entry = DB::table('lit_translation_entries')->first();
        self::assertSame('ready', $entry->status);
        self::assertSame('Sac de voyage léger', $entry->translated_text);
        self::assertSame(1, (int) $entry->attempts);
        self::assertSame(0, (int) DB::table('lit_provider_usage')->value('reserved_units'));
        self::assertSame(1, (int) DB::table('lit_provider_usage')->value('request_count'));
    }

    public function test_builder_disables_unreadable_credentials_without_poisoning_the_entry(): void
    {
        $this->migrateLibrary();
        (new TranslationLibrary)->queue('Lightweight travel bag', 'product', ['fr']);
        DB::table('lit_provider_accounts')->insert([
            'provider'                => 'siliconflow', 'billing_identity_hash' => hash('sha256', 'broken'),
            'label'                   => 'broken', 'credentials_ciphertext' => 'not-encrypted', 'credential_revision' => 1,
            'priority'                => 1, 'qps_limit' => 1, 'rpm_limit' => 60, 'tpm_limit' => 80000,
            'monthly_character_limit' => 0, 'last_dispatch_at_us' => 0, 'rate_request_count' => 0,
            'rate_token_estimate'     => 0, 'enabled' => true, 'dispatch_enabled' => true,
            'verified_at'             => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $registry = new ProviderRegistry;
        $result   = (new LibraryBuilder($registry, new ProviderAccountService($registry)))->processOne(true);

        self::assertSame('no_provider', $result['state']);
        $entry = DB::table('lit_translation_entries')->first();
        self::assertSame('pending', $entry->status);
        self::assertSame(0, (int) $entry->attempts);
        self::assertSame('NO_SUPPORTED_PROVIDER', $entry->error_code);
        $account = DB::table('lit_provider_accounts')->first();
        self::assertFalse((bool) $account->dispatch_enabled);
        self::assertNull($account->verified_at);
        self::assertSame('PROVIDER_CREDENTIALS_UNREADABLE', $account->last_error_code);
    }

    public function test_repair_migration_requeues_only_retryable_builder_failures(): void
    {
        $this->migrateLibrary();
        foreach (['NO_VERIFIED_PROVIDER', 'INVALID_INPUT'] as $error) {
            DB::table('lit_translation_entries')->insert([
                'source_locale' => 'en', 'target_locale' => 'fr', 'source_hash' => hash('sha256', $error),
                'source_text'   => $error, 'source_kind' => 'test', 'status' => 'failed', 'attempts' => 5,
                'error_code'    => $error, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        (require base_path('plugins/LongosInstantTranslate/Migrations/2026_11_11_120000_repair_translation_builder_state.php'))->up();

        self::assertSame('pending', DB::table('lit_translation_entries')->where('error_code', null)->value('status'));
        self::assertSame('failed', DB::table('lit_translation_entries')->where('error_code', 'INVALID_INPUT')->value('status'));
    }

    private function migrateLibrary(): void
    {
        (require base_path('plugins/LongosInstantTranslate/Migrations/2026_11_10_120000_create_instant_translation_library.php'))->up();
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
