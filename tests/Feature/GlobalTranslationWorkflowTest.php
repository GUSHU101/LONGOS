<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
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
}
