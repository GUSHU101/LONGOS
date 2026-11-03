<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Plugin\LongosGlobalTranslate\Controllers\ShopTranslationController;
use Plugin\LongosGlobalTranslate\Providers\ProviderRegistry;
use Plugin\LongosGlobalTranslate\Providers\TranslationProviderInterface;
use Plugin\LongosGlobalTranslate\Providers\TranslationProviderRequest;
use Plugin\LongosGlobalTranslate\Providers\TranslationProviderResponse;
use Plugin\LongosGlobalTranslate\Services\DictionaryService;
use Plugin\LongosGlobalTranslate\Services\ContentScanner;
use Plugin\LongosGlobalTranslate\Services\ProviderAccountService;
use Plugin\LongosGlobalTranslate\Services\ProviderVerificationService;
use Plugin\LongosGlobalTranslate\Services\PublicationService;
use Plugin\LongosGlobalTranslate\Services\TranslationLibrary;
use Plugin\LongosGlobalTranslate\Services\TranslationSchema;
use Plugin\LongosGlobalTranslate\Services\TranslationJobService;
use Plugin\LongosGlobalTranslate\Services\TranslationSettings;
use Plugin\LongosGlobalTranslate\Services\UsageLedger;
use RuntimeException;

class GlobalTranslationWorkflowTest extends TestCase
{
    private string $defaultConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $this->defaultConnection = DB::getDefaultConnection();
        config(['database.connections.lgt_workflow' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'tenant_translate_']]);
        DB::purge('lgt_workflow');
        DB::setDefaultConnection('lgt_workflow');
        foreach ([
            '2026_11_02_000000_create_translation_tables.php',
            '2026_11_02_000001_upgrade_translation_schema.php',
            '2026_11_02_000002_add_worker_claim_tokens.php',
            '2026_11_02_000003_repair_translation_schema.php',
            '2026_11_03_000004_add_provider_dispatch_rate_limit.php',
        ] as $migrationFile) {
            $migration = require base_path('plugins/LongosGlobalTranslate/Migrations/' . $migrationFile);
            $migration->up();
        }
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->boolean('active')->default(true);
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('product_descriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('locale');
            $table->string('name');
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('lgt_workflow');
        DB::setDefaultConnection($this->defaultConnection);
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_locked_manual_translation_publishes_immutable_snapshot_and_hides_after_source_becomes_private(): void
    {
        DB::table('products')->insert(['id' => 42, 'active' => true]);
        DB::table('product_descriptions')->insert(['product_id' => 42, 'locale' => 'en', 'name' => 'Original case']);
        $entryId = DB::table('lgt_entries')->insertGetId([
            'content_key'   => hash('sha256', 'product-42-name'), 'kind' => 'product', 'entity_id' => 42, 'field' => 'name',
            'source_locale' => 'en', 'source_format' => 'plain', 'source_text' => 'Original case', 'source_hash' => ContentScanner::sourceHash('Original case'),
            'is_public'     => true, 'is_tombstoned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $library  = new TranslationLibrary;
        $machine  = $library->saveMachineResult($entryId, 'fr', ContentScanner::sourceHash('Original case'), 'Coque originale', 'aliyun', 1);
        $edited   = $library->edit((int) $machine['id'], (int) $machine['version'], 'Coque éditée', 9, true);
        $reviewed = $library->review((int) $edited['id'], (int) $edited['version'], true, 9);
        self::assertSame(TranslationLibrary::APPROVED, $reviewed['status']);
        self::assertTrue((bool) $reviewed['locked']);

        $publication = (new PublicationService)->publish('fr', 9);
        self::assertSame(1, $publication['revision']);
        $dictionary = (new DictionaryService)->forEntity('product', 42, 'fr');
        self::assertSame('Coque éditée', $dictionary['items'][hash('sha256', 'product-42-name')]);

        DB::table('product_descriptions')->where('product_id', 42)->update(['name' => 'Changed without a scan']);
        self::assertSame([], (new DictionaryService)->forEntity('product', 42, 'fr')['items']);

        DB::table('products')->where('id', 42)->update(['active' => false]);
        self::assertSame([], (new DictionaryService)->forEntity('product', 42, 'fr')['items']);

        $rolledBack = (new PublicationService)->rollback('fr', $publication['id'], 9);
        self::assertSame(2, $rolledBack['revision']);
        self::assertSame($publication['id'], DB::table('lgt_publications')->where('id', $rolledBack['id'])->value('rollback_of'));
    }

    public function test_public_dictionary_uses_the_newest_published_snapshot_if_a_recovery_left_multiple_active_rows(): void
    {
        DB::table('products')->insert(['id' => 43, 'active' => true]);
        DB::table('product_descriptions')->insert(['product_id' => 43, 'locale' => 'en', 'name' => 'Original case']);
        $hash = ContentScanner::sourceHash('Original case');
        $entryId = DB::table('lgt_entries')->insertGetId([
            'content_key' => hash('sha256', 'product-43-name'), 'kind' => 'product', 'entity_id' => 43, 'field' => 'name',
            'source_locale' => 'en', 'source_format' => 'plain', 'source_text' => 'Original case', 'source_hash' => $hash,
            'is_public' => true, 'is_tombstoned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $translationId = DB::table('lgt_translations')->insertGetId([
            'entry_id' => $entryId, 'locale' => 'fr', 'source_hash' => $hash, 'text' => 'Unused live row',
            'status' => TranslationLibrary::APPROVED, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[1, 'Ancienne version'], [2, 'Nouvelle version']] as [$revision, $text]) {
            $publicationId = DB::table('lgt_publications')->insertGetId([
                'locale' => 'fr', 'revision' => $revision, 'state' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('lgt_publication_items')->insert([
                'publication_id' => $publicationId, 'translation_id' => $translationId, 'translation_version' => 1,
                'source_hash' => $hash, 'text' => $text, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $dictionary = (new DictionaryService)->forEntity('product', 43, 'fr');

        self::assertSame(2, $dictionary['revision']);
        self::assertSame('Nouvelle version', $dictionary['items'][hash('sha256', 'product-43-name')]);
        self::assertSame(2, (new PublicationService)->currentRevision('fr'));
    }

    public function test_disabled_storefront_selector_exposes_no_locale_configuration_or_dictionary(): void
    {
        $controller = new ShopTranslationController(
            new TranslationSettings,
            app(\Plugin\LongosGlobalTranslate\Services\PresentationLocaleResolver::class),
            new DictionaryService,
        );

        $config = $controller->config(\Illuminate\Http\Request::create('/global-translate/config'));
        $dictionary = $controller->dictionary(\Illuminate\Http\Request::create('/global-translate/dictionary/product/1?locale=fr'), 'product', 1);

        self::assertFalse($config->getData(true)['enabled']);
        self::assertSame(['en'], $config->getData(true)['locales']);
        self::assertSame('', $config->getData(true)['dictionary_url']);
        self::assertNull($dictionary->getData(true)['revision']);
        self::assertSame([], $dictionary->getData(true)['items']);
    }

    public function test_usage_ledger_hard_stops_before_free_budget_and_preserves_uncertain_cost(): void
    {
        $accountId = DB::table('lgt_provider_accounts')->insertGetId([
            'provider'               => 'aliyun', 'billing_identity_hash' => hash('sha256', 'tenant-a'), 'owner_scope' => 'tenant',
            'credentials_ciphertext' => 'not-read-in-this-test', 'credential_revision' => 1, 'free_quota' => 100, 'soft_cap' => 100,
            'paid_cap'               => 0, 'cycle_timezone' => 'UTC', 'qps_limit' => 1, 'enabled' => true, 'dispatch_enabled' => true,
            'verified_at'            => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ledger = new UsageLedger;
        for ($index = 0; $index < 99; $index++) {
            $ledger->reserve($accountId, 1);
        }
        $attempt = $ledger->reserve($accountId, 1, null, 'd07c8d2a-014f-4c54-a573-6c269d2ae118');
        self::assertSame($attempt['id'], $ledger->reserve($accountId, 1, null, 'd07c8d2a-014f-4c54-a573-6c269d2ae118')['id']);
        $this->expectException(RuntimeException::class);
        $ledger->reserve($accountId, 1);
    }

    public function test_provider_dispatch_is_durably_rate_limited_per_tenant_account(): void
    {
        $accountId = DB::table('lgt_provider_accounts')->insertGetId([
            'provider' => 'baidu', 'billing_identity_hash' => hash('sha256', 'rate-limited'), 'owner_scope' => 'tenant',
            'credentials_ciphertext' => 'not-used', 'credential_revision' => 1, 'free_quota' => 100, 'soft_cap' => 100,
            'cycle_timezone' => 'UTC', 'qps_limit' => 4, 'enabled' => true, 'dispatch_enabled' => true,
            'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ledger = new UsageLedger;
        $attempt = $ledger->reserve($accountId, 1, null, 'e9b6ca17-a2e7-4d62-ae3c-4a8c35791111');
        $at = CarbonImmutable::parse('2026-11-03 12:00:00.123456', 'UTC');

        $ledger->markSending($attempt['id'], $at);

        self::assertSame(UsageLedger::SENDING, DB::table('lgt_attempts')->where('id', $attempt['id'])->value('state'));
        self::assertSame(1_793_707_200_123_456, (int) DB::table('lgt_provider_accounts')->where('id', $accountId)->value('last_dispatch_at_us'));
    }

    public function test_pre_send_rate_limit_failure_releases_the_verification_reservation_without_contacting_a_provider(): void
    {
        $accounts = new ProviderAccountService;
        $account = $accounts->save([
            'provider' => 'baidu', 'billing_identity' => 'rate-limit-recovery', 'enabled' => true,
            'free_quota' => 100, 'soft_cap' => 100, 'qps_limit' => 1,
        ], ['appid' => 'rate-limit-app', 'secret' => 'rate-limit-secret'], 7);
        $clock = CarbonImmutable::now('UTC');
        $nowUs = ((int) $clock->format('U')) * 1_000_000 + (int) $clock->format('u');
        DB::table('lgt_provider_accounts')->where('id', $account['id'])->update(['last_dispatch_at_us' => $nowUs + 3_000_000]);
        $provider = new class implements TranslationProviderInterface
        {
            public function id(): string { return 'baidu'; }
            public function validateCredentialShape(array $credentials): void {}
            public function supports(string $sourceLocale, string $targetLocale): bool { return true; }
            public function buildBatches(array $segments, string $sourceLocale, string $targetLocale): array { return [$segments]; }
            public function send(TranslationProviderRequest $request, array $credentials): TranslationProviderResponse { self::fail('Provider must not be called before a rate-limit reservation is released.'); }
            public function classifyFailure(\Throwable|TranslationProviderResponse $failure): array { return ['kind' => 'definite', 'code' => 'TEST', 'definitely_unbilled' => true]; }
        };
        $verification = new ProviderVerificationService($accounts, new ProviderRegistry(['baidu' => $provider]), new UsageLedger);

        try {
            $verification->verify((int) $account['id'], 7);
            self::fail('Expected a safe pre-send rate-limit failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('PROVIDER_RATE_LIMIT_CLOCK_SKEW', $exception->getMessage());
        }

        self::assertSame(UsageLedger::DEFINITE_FAILED, DB::table('lgt_attempts')->where('provider_account_id', $account['id'])->value('state'));
    }

    public function test_provider_account_secret_never_round_trips_and_source_language_cannot_change(): void
    {
        $accounts = new ProviderAccountService;
        $account  = $accounts->save([
            'provider' => 'baidu', 'billing_identity' => 'sandbox-profile', 'enabled' => true, 'free_quota' => 1000,
        ], ['appid' => 'app-id', 'secret' => 'secret-value'], null);
        self::assertTrue($account['credentials_configured']);
        self::assertArrayNotHasKey('credentials_ciphertext', $account);
        self::assertSame(0, $account['paid_cap']);
        self::assertFalse((bool) $account['dispatch_enabled']);

        $this->expectException(InvalidArgumentException::class);
        (new TranslationSettings)->save(['source_locale' => 'fr']);
    }

    public function test_schema_repair_restores_an_interrupted_plugin_migration_without_dropping_data(): void
    {
        Schema::drop('lgt_coverage_snapshots');
        Schema::table('lgt_job_items', function (Blueprint $table): void {
            $table->dropIndex('lgt_job_item_claim');
            $table->dropColumn('claim_token');
        });
        Schema::table('lgt_provider_accounts', function (Blueprint $table): void {
            $table->dropColumn('last_dispatch_at_us');
        });

        self::assertFalse(TranslationSchema::inspect()['ready']);

        $repair = require base_path('plugins/LongosGlobalTranslate/Migrations/2026_11_02_000003_repair_translation_schema.php');
        $repair->up();

        self::assertTrue(TranslationSchema::inspect()['ready']);
        self::assertTrue(Schema::hasTable('lgt_coverage_snapshots'));
        self::assertTrue(Schema::hasColumn('lgt_job_items', 'claim_token'));
        self::assertTrue(Schema::hasColumn('lgt_provider_accounts', 'last_dispatch_at_us'));
    }

    public function test_malformed_persisted_settings_fail_closed_without_breaking_the_admin_workspace(): void
    {
        DB::table('lgt_settings')->insert([
            'key'        => 'mode',
            'value'      => json_encode('unsafe_unknown_mode'),
            'revision'   => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $settings = (new TranslationSettings)->all();

        self::assertSame(TranslationSettings::MODE_PREVIEW, $settings['mode']);
        self::assertSame('en', $settings['source_locale']);
        self::assertFalse((bool) $settings['widget']['enabled']);
    }

    public function test_controlled_provider_verification_commits_one_journaled_probe_but_keeps_dispatch_off(): void
    {
        $accounts = new ProviderAccountService;
        $account  = $accounts->save([
            'provider' => 'baidu', 'billing_identity' => 'verification-sandbox', 'enabled' => true, 'free_quota' => 1000,
        ], ['appid' => 'app-id', 'secret' => 'secret-value'], 8);
        $provider = new class implements TranslationProviderInterface
        {
            public function id(): string
            {
                return 'baidu';
            }

            public function validateCredentialShape(array $credentials): void {}

            public function supports(string $sourceLocale, string $targetLocale): bool
            {
                return true;
            }

            public function buildBatches(array $segments, string $sourceLocale, string $targetLocale): array
            {
                return [$segments];
            }

            public function send(TranslationProviderRequest $request, array $credentials): TranslationProviderResponse
            {
                return new TranslationProviderResponse(true, ['Vérification LONGOS'], 'provider-probe', null, hash('sha256', 'probe'), 1);
            }

            public function classifyFailure(\Throwable|TranslationProviderResponse $failure): array
            {
                return ['kind' => 'definite', 'code' => 'TEST', 'definitely_unbilled' => true];
            }
        };
        $verification = new ProviderVerificationService($accounts, new ProviderRegistry(['baidu' => $provider]), new UsageLedger);
        $verified     = $verification->verify((int) $account['id'], 8);

        self::assertNotNull($verified['verified_at']);
        self::assertFalse((bool) $verified['dispatch_enabled']);
        self::assertSame('committed', DB::table('lgt_attempts')->value('state'));
        self::assertSame(19, (int) DB::table('lgt_usage_months')->value('committed_units'));
    }

    public function test_missing_dispatchable_account_pauses_without_losing_or_requeuing_the_claimed_item(): void
    {
        (new TranslationSettings)->save(['mode' => TranslationSettings::MODE_FREE_ONLY, 'enabled_locales' => ['fr']]);
        $entryId = DB::table('lgt_entries')->insertGetId([
            'content_key'   => hash('sha256', 'queued-product'), 'kind' => 'product', 'entity_id' => 98, 'field' => 'name',
            'source_locale' => 'en', 'source_format' => 'plain', 'source_text' => 'Queued case', 'source_hash' => ContentScanner::sourceHash('Queued case'),
            'is_public' => true, 'is_tombstoned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $jobId = DB::table('lgt_jobs')->insertGetId([
            'public_id' => '7924b49f-e6ce-4eb6-a3be-bfab91011111', 'status' => 'queued', 'mode' => TranslationSettings::MODE_FREE_ONLY,
            'scope' => json_encode(['product']), 'target_locales' => json_encode(['fr']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('lgt_job_items')->insert([
            'job_id' => $jobId, 'entry_id' => $entryId, 'target_locale' => 'fr', 'source_hash' => ContentScanner::sourceHash('Queued case'),
            'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = new TranslationJobService(new ContentScanner, new TranslationSettings, new ProviderAccountService, new ProviderRegistry, new UsageLedger, new TranslationLibrary);
        $service->process($jobId, 1, '95a4ca5b-7da2-4d67-bc1e-d7849d800001');

        self::assertSame('paused', DB::table('lgt_jobs')->where('id', $jobId)->value('status'));
        self::assertSame(0, (int) DB::table('lgt_jobs')->where('id', $jobId)->value('completed_items'));
        self::assertSame(0, (int) DB::table('lgt_jobs')->where('id', $jobId)->value('failed_items'));
        self::assertSame('ready', DB::table('lgt_job_items')->where('job_id', $jobId)->value('status'));
        self::assertNull(DB::table('lgt_job_items')->where('job_id', $jobId)->value('claim_token'));
    }

    public function test_failed_worker_can_only_finish_claims_owned_by_its_run_token(): void
    {
        $jobId = DB::table('lgt_jobs')->insertGetId([
            'public_id' => '40ee7d26-8c6f-40f4-9159-5fb9423b2222', 'status' => 'paused', 'mode' => TranslationSettings::MODE_FREE_ONLY,
            'scope' => json_encode(['product']), 'target_locales' => json_encode(['fr']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['run-a', 'run-b'] as $index => $token) {
            DB::table('lgt_job_items')->insert([
                'job_id' => $jobId, 'entry_id' => 500 + $index, 'target_locale' => 'fr', 'source_hash' => str_repeat((string) ($index + 1), 64),
                'status' => 'claimed', 'claim_token' => $token, 'claimed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $service = new TranslationJobService(new ContentScanner, new TranslationSettings, new ProviderAccountService, new ProviderRegistry, new UsageLedger, new TranslationLibrary);
        $service->failClaimsForRun($jobId, 'run-a');

        self::assertSame('failed', DB::table('lgt_job_items')->where('job_id', $jobId)->where('claim_token', null)->value('status'));
        self::assertSame('claimed', DB::table('lgt_job_items')->where('job_id', $jobId)->where('claim_token', 'run-b')->value('status'));
        self::assertSame(1, (int) DB::table('lgt_jobs')->where('id', $jobId)->value('completed_items'));
        self::assertSame(1, (int) DB::table('lgt_jobs')->where('id', $jobId)->value('failed_items'));
    }

    public function test_owned_claim_completes_and_releases_its_token_after_a_provider_result(): void
    {
        (new TranslationSettings)->save(['mode' => TranslationSettings::MODE_FREE_ONLY, 'enabled_locales' => ['fr']]);
        $accounts = new ProviderAccountService;
        $account = $accounts->save([
            'provider' => 'baidu', 'billing_identity' => 'queue-success', 'enabled' => true, 'free_quota' => 1_000, 'soft_cap' => 1_000,
        ], ['appid' => 'queue-app', 'secret' => 'queue-secret'], 11);
        $accounts->markVerified((int) $account['id'], 11);
        $accounts->setDispatch((int) $account['id'], true, 11);

        $entryId = DB::table('lgt_entries')->insertGetId([
            'content_key'   => hash('sha256', 'completed-product'), 'kind' => 'product', 'entity_id' => 99, 'field' => 'name',
            'source_locale' => 'en', 'source_format' => 'plain', 'source_text' => 'Protected case', 'source_hash' => ContentScanner::sourceHash('Protected case'),
            'is_public' => true, 'is_tombstoned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $jobId = DB::table('lgt_jobs')->insertGetId([
            'public_id' => '4d97dbfa-26de-4859-a75f-d0713d2a3333', 'status' => 'queued', 'mode' => TranslationSettings::MODE_FREE_ONLY,
            'scope' => json_encode(['product']), 'target_locales' => json_encode(['fr']), 'total_items' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('lgt_job_items')->insert([
            'job_id' => $jobId, 'entry_id' => $entryId, 'target_locale' => 'fr', 'source_hash' => ContentScanner::sourceHash('Protected case'),
            'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $provider = new class implements TranslationProviderInterface
        {
            public function id(): string { return 'baidu'; }
            public function validateCredentialShape(array $credentials): void {}
            public function supports(string $sourceLocale, string $targetLocale): bool { return $sourceLocale === 'en' && $targetLocale === 'fr'; }
            public function buildBatches(array $segments, string $sourceLocale, string $targetLocale): array { return [$segments]; }
            public function send(TranslationProviderRequest $request, array $credentials): TranslationProviderResponse { return new TranslationProviderResponse(true, ['Étui protégé'], 'queue-result', null, hash('sha256', 'queue-result'), 1); }
            public function classifyFailure(\Throwable|TranslationProviderResponse $failure): array { return ['kind' => 'definite', 'code' => 'TEST', 'definitely_unbilled' => true]; }
        };
        $service = new TranslationJobService(new ContentScanner, new TranslationSettings, $accounts, new ProviderRegistry(['baidu' => $provider]), new UsageLedger, new TranslationLibrary);
        $service->process($jobId, 1, 'e2ec17a3-7321-447b-a010-8b42a3550001');

        self::assertSame('completed', DB::table('lgt_jobs')->where('id', $jobId)->value('status'));
        self::assertSame('completed', DB::table('lgt_job_items')->where('job_id', $jobId)->value('status'));
        self::assertNull(DB::table('lgt_job_items')->where('job_id', $jobId)->value('claim_token'));
        self::assertSame('Étui protégé', DB::table('lgt_translations')->where('entry_id', $entryId)->value('text'));
    }

    public function test_lost_worker_releases_only_a_pre_send_reservation_and_quarantines_a_sending_attempt(): void
    {
        $accounts = new ProviderAccountService;
        $account = $accounts->save([
            'provider' => 'baidu', 'billing_identity' => 'worker-loss', 'enabled' => true, 'free_quota' => 100, 'soft_cap' => 100,
        ], ['appid' => 'worker-loss-app', 'secret' => 'worker-loss-secret'], 12);
        $accounts->markVerified((int) $account['id'], 12);
        $accounts->setDispatch((int) $account['id'], true, 12);
        $ledger = new UsageLedger;

        $reserved = $ledger->reserve((int) $account['id'], 10);
        $ledger->recoverWorkerLoss($reserved['id']);
        self::assertSame(UsageLedger::DEFINITE_FAILED, DB::table('lgt_attempts')->where('id', $reserved['id'])->value('state'));

        $sending = $ledger->reserve((int) $account['id'], 10);
        $ledger->markSending($sending['id']);
        $ledger->recoverWorkerLoss($sending['id']);
        self::assertSame(UsageLedger::UNCERTAIN, DB::table('lgt_attempts')->where('id', $sending['id'])->value('state'));
        self::assertSame(10, (int) DB::table('lgt_usage_months')->where('provider_account_id', $account['id'])->value('uncertain_units'));
    }
}
