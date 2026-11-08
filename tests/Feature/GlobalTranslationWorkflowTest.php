<?php

namespace Tests\Feature;

use Beike\Services\CloudflareIpRangeService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Plugin\LongosGlobalTranslate\Controllers\ShopTranslationController;
use Plugin\LongosGlobalTranslate\Jobs\ProcessTranslationJob;
use Plugin\LongosGlobalTranslate\Providers\ProviderRegistry;
use Plugin\LongosGlobalTranslate\Providers\TranslationProviderInterface;
use Plugin\LongosGlobalTranslate\Providers\TranslationProviderRequest;
use Plugin\LongosGlobalTranslate\Providers\TranslationProviderResponse;
use Plugin\LongosGlobalTranslate\Services\CommerceUiCatalog;
use Plugin\LongosGlobalTranslate\Services\ContentScanner;
use Plugin\LongosGlobalTranslate\Services\DictionaryService;
use Plugin\LongosGlobalTranslate\Services\LocaleCoverageService;
use Plugin\LongosGlobalTranslate\Services\PlatformPaymentLocaleBridge;
use Plugin\LongosGlobalTranslate\Services\ProviderAccountService;
use Plugin\LongosGlobalTranslate\Services\ProviderVerificationService;
use Plugin\LongosGlobalTranslate\Services\PublicationService;
use Plugin\LongosGlobalTranslate\Services\StorefrontPresentationConfig;
use Plugin\LongosGlobalTranslate\Services\TranslationJobService;
use Plugin\LongosGlobalTranslate\Services\TranslationLibrary;
use Plugin\LongosGlobalTranslate\Services\TranslationSchema;
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
            '2026_11_03_000005_add_provider_failover_priority.php',
            '2026_11_04_000006_add_job_item_failure_codes.php',
            '2026_11_04_000007_backfill_job_item_failure_codes.php',
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
        $hash    = ContentScanner::sourceHash('Original case');
        $entryId = DB::table('lgt_entries')->insertGetId([
            'content_key'   => hash('sha256', 'product-43-name'), 'kind' => 'product', 'entity_id' => 43, 'field' => 'name',
            'source_locale' => 'en', 'source_format' => 'plain', 'source_text' => 'Original case', 'source_hash' => $hash,
            'is_public'     => true, 'is_tombstoned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $translationId = DB::table('lgt_translations')->insertGetId([
            'entry_id' => $entryId, 'locale' => 'fr', 'source_hash' => $hash, 'text' => 'Unused live row',
            'status'   => TranslationLibrary::APPROVED, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[1, 'Ancienne version'], [2, 'Nouvelle version']] as [$revision, $text]) {
            $publicationId = DB::table('lgt_publications')->insertGetId([
                'locale' => 'fr', 'revision' => $revision, 'state' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('lgt_publication_items')->insert([
                'publication_id' => $publicationId, 'translation_id' => $translationId, 'translation_version' => 1,
                'source_hash'    => $hash, 'text' => $text, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $dictionary = (new DictionaryService)->forEntity('product', 43, 'fr');

        self::assertSame(2, $dictionary['revision']);
        self::assertSame('Nouvelle version', $dictionary['items'][hash('sha256', 'product-43-name')]);
        self::assertSame(2, (new PublicationService)->currentRevision('fr'));
    }

    public function test_batch_dictionary_returns_only_current_public_entries_and_exposes_the_exact_public_source_for_safe_theme_fallback(): void
    {
        $settings = new TranslationSettings;
        $settings->save([
            ...$settings->all(),
            'enabled_locales' => ['fr'],
            'widget'          => ['enabled' => true, 'position' => 'right', 'bottom_offset' => 84, 'mobile_bottom_offset' => 88, 'theme' => 'system'],
        ]);

        DB::table('products')->insert([
            ['id' => 44, 'active' => true],
            ['id' => 47, 'active' => true],
        ]);
        DB::table('product_descriptions')->insert([
            ['product_id' => 44, 'locale' => 'en', 'name' => 'Public travel case'],
            ['product_id' => 47, 'locale' => 'en', 'name' => 'Public sling case'],
        ]);
        $hash       = ContentScanner::sourceHash('Public travel case');
        $secondHash = ContentScanner::sourceHash('Public sling case');
        $entryId    = DB::table('lgt_entries')->insertGetId([
            'content_key'   => hash('sha256', 'product-44-name'), 'kind' => 'product', 'entity_id' => 44, 'field' => 'name',
            'source_locale' => 'en', 'source_format' => 'plain', 'source_text' => 'Public travel case', 'source_hash' => $hash,
            'is_public'     => true, 'is_tombstoned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $translationId = DB::table('lgt_translations')->insertGetId([
            'entry_id' => $entryId, 'locale' => 'fr', 'source_hash' => $hash, 'text' => 'Étui de voyage public',
            'status'   => TranslationLibrary::APPROVED, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $secondEntryId = DB::table('lgt_entries')->insertGetId([
            'content_key'   => hash('sha256', 'product-47-name'), 'kind' => 'product', 'entity_id' => 47, 'field' => 'name',
            'source_locale' => 'en', 'source_format' => 'plain', 'source_text' => 'Public sling case', 'source_hash' => $secondHash,
            'is_public'     => true, 'is_tombstoned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $secondTranslationId = DB::table('lgt_translations')->insertGetId([
            'entry_id' => $secondEntryId, 'locale' => 'fr', 'source_hash' => $secondHash, 'text' => 'Étui bandoulière public',
            'status'   => TranslationLibrary::APPROVED, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $publicationId = DB::table('lgt_publications')->insertGetId([
            'locale' => 'fr', 'revision' => 1, 'state' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('lgt_publication_items')->insert([
            'publication_id' => $publicationId, 'translation_id' => $translationId, 'translation_version' => 1,
            'source_hash'    => $hash, 'text' => 'Étui de voyage public', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('lgt_publication_items')->insert([
            'publication_id' => $publicationId, 'translation_id' => $secondTranslationId, 'translation_version' => 1,
            'source_hash'    => $secondHash, 'text' => 'Étui bandoulière public', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $controller = new ShopTranslationController(
            $settings,
            app(\Plugin\LongosGlobalTranslate\Services\PresentationLocaleResolver::class),
            new DictionaryService,
        );
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $response = $controller->batchDictionary(\Illuminate\Http\Request::create('/global-translate/dictionary/batch', 'GET', [
            'locale' => 'fr', 'entities' => 'product:44,product:47,product:999999',
        ]));
        $dictionary = $response->getData(true)['dictionaries']['product:44'];

        self::assertSame('Étui de voyage public', $dictionary['items'][hash('sha256', 'product-44-name')]);
        self::assertSame('Étui de voyage public', $dictionary['fields']['name']);
        self::assertSame('Public travel case', $dictionary['sources'][hash('sha256', 'product-44-name')]);
        self::assertSame('Étui bandoulière public', $response->getData(true)['dictionaries']['product:47']['fields']['name']);
        self::assertArrayNotHasKey('product:999999', $response->getData(true)['dictionaries']);
        $publicationReads = array_filter($queries, static fn (string $sql): bool => preg_match('/from\s+["`][^"`]*lgt_publications["`]\s+where.*\blocale\b/i', $sql) === 1);
        self::assertCount(1, $publicationReads, 'A single batched public read must resolve one immutable publication snapshot.');

        DB::table('product_descriptions')->where('product_id', 44)->update(['name' => 'Changed after publication']);
        $stale = $controller->batchDictionary(\Illuminate\Http\Request::create('/global-translate/dictionary/batch', 'GET', [
            'locale' => 'fr', 'entities' => 'product:44',
        ]));
        self::assertSame([], $stale->getData(true)['dictionaries']);
    }

    public function test_storefront_bootstrap_uses_the_published_locale_revision_for_a_tenant_scoped_cache_key(): void
    {
        foreach ([
            'shop.longos_global_translate.dictionary'           => '/__lgt-test/dictionary/{kind}/{entityId}',
            'shop.longos_global_translate.dictionary-batch'     => '/__lgt-test/dictionary/batch',
            'shop.longos_global_translate.interface-dictionary' => '/__lgt-test/dictionary/interface',
        ] as $name => $uri) {
            if (! Route::has($name)) {
                Route::get($uri, static fn () => response()->noContent())->name($name);
            }
        }
        // The application was bootstrapped before this focused test adds its
        // isolated route fixtures, so rebuild Laravel's route-name index.
        Route::getRoutes()->refreshNameLookups();

        $settings = new TranslationSettings;
        $settings->save([
            ...$settings->all(),
            'enabled_locales' => ['fr'],
            'widget'          => ['enabled' => true, 'position' => 'right', 'bottom_offset' => 84, 'mobile_bottom_offset' => 88, 'theme' => 'system'],
        ]);
        $hash    = ContentScanner::sourceHash('Published builder heading');
        $entryId = DB::table('lgt_entries')->insertGetId([
            'content_key'   => hash('sha256', 'builder-99-heading'), 'kind' => 'builder', 'entity_id' => 99, 'field' => 'module.0.heading',
            'source_locale' => 'en', 'source_format' => 'plain', 'source_text' => 'Published builder heading', 'source_hash' => $hash,
            'is_public'     => true, 'is_tombstoned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $translationId = DB::table('lgt_translations')->insertGetId([
            'entry_id' => $entryId, 'locale' => 'fr', 'source_hash' => $hash, 'text' => 'Titre de module publié',
            'status'   => TranslationLibrary::APPROVED, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $publicationId = DB::table('lgt_publications')->insertGetId([
            'locale' => 'fr', 'revision' => 7, 'state' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('lgt_publication_items')->insert([
            'publication_id' => $publicationId, 'translation_id' => $translationId, 'translation_version' => 1,
            'source_hash'    => $hash, 'text' => 'Titre de module publié', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $request = Request::create('/products/99', 'GET', [], [\Plugin\LongosGlobalTranslate\Services\PresentationLocaleResolver::COOKIE => 'fr']);
        $config  = (new StorefrontPresentationConfig(
            $settings,
            app(\Plugin\LongosGlobalTranslate\Services\PresentationLocaleResolver::class),
        ))->forRequest($request);

        self::assertTrue($config['enabled']);
        self::assertSame('fr', $config['locale']);
        self::assertSame('explicit', $config['locale_source']);
        self::assertSame(['fr'], $config['published_locales']);
        self::assertSame(['fr' => 7], $config['published_revisions']);
        self::assertSame(['99'], $config['builder_entities']);
        self::assertNotSame('', $config['dictionary_url']);
        self::assertStringContainsString('__kind__', $config['dictionary_url']);
        self::assertStringContainsString('__entity__', $config['dictionary_url']);
        self::assertNotSame('', $config['batch_dictionary_url']);
        self::assertNotSame('', $config['interface_dictionary_url']);
    }

    public function test_quick_publish_approves_only_safe_machine_results_and_keeps_human_edits_for_explicit_review(): void
    {
        DB::table('products')->insert(['id' => 45, 'active' => true]);
        DB::table('products')->insert(['id' => 46, 'active' => true]);
        DB::table('product_descriptions')->insert(['product_id' => 45, 'locale' => 'en', 'name' => 'Machine source']);
        DB::table('product_descriptions')->insert(['product_id' => 46, 'locale' => 'en', 'name' => 'Human source']);
        $library = new TranslationLibrary;

        $machineEntry = DB::table('lgt_entries')->insertGetId([
            'content_key'   => hash('sha256', 'product-45-name'), 'kind' => 'product', 'entity_id' => 45, 'field' => 'name',
            'source_locale' => 'en', 'source_format' => 'plain', 'source_text' => 'Machine source', 'source_hash' => ContentScanner::sourceHash('Machine source'),
            'is_public'     => true, 'is_tombstoned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $humanEntry = DB::table('lgt_entries')->insertGetId([
            'content_key'   => hash('sha256', 'product-46-name'), 'kind' => 'product', 'entity_id' => 46, 'field' => 'name',
            'source_locale' => 'en', 'source_format' => 'plain', 'source_text' => 'Human source', 'source_hash' => ContentScanner::sourceHash('Human source'),
            'is_public'     => true, 'is_tombstoned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $machine = $library->saveMachineResult($machineEntry, 'fr', ContentScanner::sourceHash('Machine source'), 'Source automatique', 'baidu', 10);
        $human   = $library->saveMachineResult($humanEntry, 'fr', ContentScanner::sourceHash('Human source'), 'Source humaine', 'baidu', 10);
        $library->edit((int) $human['id'], (int) $human['version'], 'Texte révisé manuellement', 11, true);

        $result = (new PublicationService)->publishReady('fr', 12, $library);

        self::assertSame(1, $result['auto_approved']);
        self::assertSame(1, $result['publication']['coverage']['approved']);
        self::assertSame(TranslationLibrary::APPROVED, DB::table('lgt_translations')->where('id', $machine['id'])->value('status'));
        self::assertSame(TranslationLibrary::NEEDS_REVIEW, DB::table('lgt_translations')->where('id', $human['id'])->value('status'));
        self::assertNull(DB::table('lgt_translations')->where('id', $human['id'])->value('provider'));
        self::assertSame('auto_basic_check', DB::table('lgt_translation_versions')->where('translation_id', $machine['id'])->orderByDesc('id')->value('reason'));
    }

    public function test_disabled_storefront_selector_exposes_no_locale_configuration_or_dictionary(): void
    {
        $controller = new ShopTranslationController(
            new TranslationSettings,
            app(\Plugin\LongosGlobalTranslate\Services\PresentationLocaleResolver::class),
            new DictionaryService,
        );

        $config     = $controller->config(\Illuminate\Http\Request::create('/global-translate/config'));
        $dictionary = $controller->dictionary(\Illuminate\Http\Request::create('/global-translate/dictionary/product/1?locale=fr'), 'product', 1);

        self::assertFalse($config->getData(true)['enabled']);
        self::assertSame(['en'], $config->getData(true)['locales']);
        self::assertSame('1.5.7', $config->getData(true)['plugin_release']);
        self::assertSame([], $config->getData(true)['published_revisions']);
        self::assertSame('', $config->getData(true)['dictionary_url']);
        self::assertSame('', $config->getData(true)['batch_dictionary_url']);
        self::assertSame('', $config->getData(true)['interface_dictionary_url']);
        self::assertNull($dictionary->getData(true)['revision']);
        self::assertSame([], $dictionary->getData(true)['items']);
    }

    public function test_trusted_visitor_ip_automatically_applies_an_enabled_catalog_language_without_confirmation(): void
    {
        $settings = new TranslationSettings;
        $settings->save([
            ...$settings->all(),
            'enabled_locales'         => ['fr', 'ja'],
            'geo_auto_switch_enabled' => true,
        ]);
        config(['currency.trust_cdn_country_headers' => true]);

        $request = Request::create('/products/44');
        $request->headers->set('CF-IPCountry', 'JP');
        $request->attributes->set(CloudflareIpRangeService::REQUEST_ATTRIBUTE, true);

        $resolver = app(\Plugin\LongosGlobalTranslate\Services\PresentationLocaleResolver::class);
        $decision = $resolver->decide($request);

        self::assertSame('ja', $decision->locale);
        self::assertSame('geo_catalog', $decision->source);
        self::assertSame('JP', $decision->country);
        self::assertFalse($decision->fallback);

        // An explicit tenant rule remains the only intentional override of
        // the catalogue; the visitor still receives it automatically.
        DB::table('lgt_country_rules')->insert([
            'country'    => 'JP', 'locale' => 'fr', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ruleDecision = $resolver->decide($request);
        self::assertSame('fr', $ruleDecision->locale);
        self::assertSame('geo_rule', $ruleDecision->source);
    }

    public function test_reviewed_commerce_interface_labels_publish_without_exposing_transaction_values(): void
    {
        $scanner = new ContentScanner;
        $report  = $scanner->scan([CommerceUiCatalog::KIND]);
        self::assertContains(CommerceUiCatalog::KIND, $report['supported_sources']);
        self::assertSame(count(CommerceUiCatalog::entries()), $report['found']);

        $entry = DB::table('lgt_entries')
            ->where('kind', CommerceUiCatalog::KIND)
            ->where('entity_id', CommerceUiCatalog::ENTITY_ID)
            ->where('field', 'checkout.order_summary')
            ->first();
        self::assertNotNull($entry);
        self::assertSame('Order summary', $entry->source_text);
        self::assertSame(0, DB::table('lgt_entries')->whereIn('kind', ['order', 'payment', 'customer', 'checkout'])->count());

        $library = new TranslationLibrary;
        $machine = $library->saveMachineResult((int) $entry->id, 'fr', (string) $entry->source_hash, 'Récapitulatif de la commande', 'baidu', 1);
        $library->review((int) $machine['id'], (int) $machine['version'], true, 7);
        (new PublicationService)->publish('fr', 7);

        $dictionary = (new DictionaryService)->forCommerceInterface('fr');
        self::assertSame('Order summary', $dictionary['items']['checkout.order_summary']['source']);
        self::assertSame('Récapitulatif de la commande', $dictionary['items']['checkout.order_summary']['text']);

        DB::table('lgt_entries')->where('id', $entry->id)->update(['source_text' => 'Tampered static label']);
        self::assertSame([], (new DictionaryService)->forCommerceInterface('fr')['items']);
    }

    public function test_coverage_requires_a_complete_published_commerce_ui_catalog_for_checkout_readiness(): void
    {
        $settings = new TranslationSettings;
        $current  = $settings->all();
        $settings->save([
            ...$current,
            'enabled_locales' => ['fr'],
            'widget'          => [...(array) ($current['widget'] ?? []), 'enabled' => true],
        ]);

        DB::table('products')->insert(['id' => 51, 'active' => true]);
        DB::table('product_descriptions')->insert([
            'product_id' => 51, 'locale' => 'en', 'name' => 'Travel bottle',
        ]);

        $scanner = new ContentScanner;
        $scanner->scan(['product']);
        $product = DB::table('lgt_entries')->where('kind', 'product')->first();
        self::assertNotNull($product);

        $library     = new TranslationLibrary;
        $translation = $library->saveMachineResult(
            (int) $product->id,
            'fr',
            (string) $product->source_hash,
            'Bouteille de voyage',
            'baidu',
            1,
        );
        $library->review((int) $translation['id'], (int) $translation['version'], true, 7);
        (new PublicationService)->publish('fr', 7);

        $coverage  = new LocaleCoverageService;
        $before    = $coverage->snapshot($settings->all());
        $beforeRow = $before['locales'][0];
        self::assertTrue($before['ready_to_display']);
        self::assertFalse($before['commerce_ui_ready_to_display']);
        self::assertSame(0, $beforeRow['commerce_ui_sources']);
        self::assertFalse($beforeRow['commerce_ui_ready']);
        self::assertStringContainsString('固定界面词典', $beforeRow['next_action']);

        $scanner->scan([CommerceUiCatalog::KIND]);
        $now          = now();
        $translations = DB::table('lgt_entries')
            ->where('kind', CommerceUiCatalog::KIND)
            ->where('entity_id', CommerceUiCatalog::ENTITY_ID)
            ->orderBy('id')
            ->get(['id', 'source_hash'])
            ->map(static fn (object $entry): array => [
                'entry_id'    => $entry->id,
                'locale'      => 'fr',
                'source_hash' => $entry->source_hash,
                'text'        => 'FR-' . $entry->id,
                'status'      => TranslationLibrary::APPROVED,
                'locked'      => false,
                'version'     => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        foreach ($translations->chunk(200) as $chunk) {
            DB::table('lgt_translations')->insert($chunk->all());
        }
        (new PublicationService)->publish('fr', 7);

        $after    = $coverage->snapshot($settings->all());
        $afterRow = $after['locales'][0];
        self::assertSame(count(CommerceUiCatalog::entries()), $afterRow['commerce_ui_expected']);
        self::assertSame(count(CommerceUiCatalog::entries()), $afterRow['commerce_ui_sources']);
        self::assertSame(count(CommerceUiCatalog::entries()), $afterRow['commerce_ui_published']);
        self::assertTrue($afterRow['commerce_ui_ready']);
        self::assertTrue($after['commerce_ui_ready_to_display']);
    }

    public function test_hosted_payment_bridge_accepts_only_published_static_platform_labels(): void
    {
        $bridge = app(PlatformPaymentLocaleBridge::class);
        $method = new \ReflectionMethod($bridge, 'platformPaymentLines');
        $lines  = $method->invoke($bridge, [
            'theme.lang.en.platform_pay.title'            => ['source' => 'Complete payment', 'text' => 'Paiement terminé'],
            'theme.lang.en.platform_pay.status.completed' => ['source' => 'Payment completed.', 'text' => 'Paiement terminé.'],
            // This source is deduplicated in the shared public catalog. The
            // payment bridge must still apply it to its own Laravel key.
            'checkout.secure_checkout'                    => ['source' => 'Secure checkout', 'text' => 'Paiement sécurisé'],
            'checkout.order_total'                        => ['source' => 'Order Total', 'text' => 'Total'],
        ]);

        self::assertSame([
            'platform_pay.title'            => 'Paiement terminé',
            'platform_pay.eyebrow'          => 'Paiement sécurisé',
            'platform_pay.status.completed' => 'Paiement terminé.',
        ], $lines);

        $expected = new \ReflectionMethod($bridge, 'platformPaymentStaticSources');
        $sources  = $expected->invoke($bridge);
        self::assertArrayHasKey('platform_pay.title', $sources);
        self::assertArrayHasKey('platform_pay.eyebrow', $sources);
        self::assertArrayNotHasKey('platform_pay.collected_by', $sources);
        self::assertGreaterThan(count($lines), count($sources));
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
            'provider'               => 'baidu', 'billing_identity_hash' => hash('sha256', 'rate-limited'), 'owner_scope' => 'tenant',
            'credentials_ciphertext' => 'not-used', 'credential_revision' => 1, 'free_quota' => 100, 'soft_cap' => 100,
            'cycle_timezone'         => 'UTC', 'qps_limit' => 4, 'enabled' => true, 'dispatch_enabled' => true,
            'verified_at'            => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ledger  = new UsageLedger;
        $attempt = $ledger->reserve($accountId, 1, null, 'e9b6ca17-a2e7-4d62-ae3c-4a8c35791111');
        $at      = CarbonImmutable::parse('2026-11-03 12:00:00.123456', 'UTC');

        $ledger->markSending($attempt['id'], $at);

        self::assertSame(UsageLedger::SENDING, DB::table('lgt_attempts')->where('id', $attempt['id'])->value('state'));
        self::assertSame(1_793_707_200_123_456, (int) DB::table('lgt_provider_accounts')->where('id', $accountId)->value('last_dispatch_at_us'));
    }

    public function test_pre_send_rate_limit_failure_releases_the_verification_reservation_without_contacting_a_provider(): void
    {
        $accounts = new ProviderAccountService;
        $account  = $accounts->save([
            'provider'   => 'baidu', 'billing_identity' => 'rate-limit-recovery', 'enabled' => true,
            'free_quota' => 100, 'soft_cap' => 100, 'qps_limit' => 1,
        ], ['appid' => 'rate-limit-app', 'secret' => 'rate-limit-secret'], 7);
        $clock = CarbonImmutable::now('UTC');
        $nowUs = ((int) $clock->format('U')) * 1_000_000 + (int) $clock->format('u');
        DB::table('lgt_provider_accounts')->where('id', $account['id'])->update(['last_dispatch_at_us' => $nowUs + 3_000_000]);
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
                self::fail('Provider must not be called before a rate-limit reservation is released.');
            }

            public function classifyFailure(\Throwable|TranslationProviderResponse $failure): array
            {
                return ['kind' => 'definite', 'code' => 'TEST', 'definitely_unbilled' => true];
            }
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

    public function test_provider_account_limits_can_be_edited_without_reentering_or_rotating_credentials(): void
    {
        $accounts = new ProviderAccountService;
        $account  = $accounts->save([
            'provider'          => 'baidu',
            'billing_identity'  => 'editable-quota-account',
            'account_tier'      => '旧免费套餐',
            'enabled'           => true,
            'free_quota'        => 1_000,
            'soft_cap'          => 900,
            'qps_limit'         => 1,
            'failover_priority' => 20,
        ], ['appid' => 'app-id', 'secret' => 'secret-value'], 4);
        $accounts->markVerified((int) $account['id'], 4);
        $accounts->setDispatch((int) $account['id'], true, 4);
        $before = DB::table('lgt_provider_accounts')->where('id', $account['id'])->first();

        $updated = $accounts->update((int) $account['id'], [
            'account_tier'          => '新免费套餐',
            'free_quota'            => 8_000,
            'soft_cap'              => 7_500,
            'external_usage_buffer' => 200,
            'cycle_timezone'        => 'Asia/Shanghai',
            'qps_limit'             => 3,
            'failover_priority'     => 5,
            'enabled'               => true,
            'source_link'           => 'https://fanyi-api.baidu.com/',
        ], [], 5);
        $after = DB::table('lgt_provider_accounts')->where('id', $account['id'])->first();

        self::assertSame('新免费套餐', $updated['account_tier']);
        self::assertSame(8_000, (int) $updated['free_quota']);
        self::assertSame(7_500, (int) $updated['soft_cap']);
        self::assertSame(3, (int) $updated['qps_limit']);
        self::assertTrue((bool) $updated['credentials_configured']);
        self::assertTrue((bool) $updated['dispatch_enabled']);
        self::assertNotEmpty($updated['verified_at']);
        self::assertArrayNotHasKey('credentials_ciphertext', $updated);
        self::assertSame($before->credentials_ciphertext, $after->credentials_ciphertext);
        self::assertSame((int) $before->credential_revision, (int) $after->credential_revision);
    }

    public function test_rotating_provider_credentials_requires_a_fresh_verification_before_dispatch(): void
    {
        $accounts = new ProviderAccountService;
        $account  = $accounts->save([
            'provider' => 'baidu', 'billing_identity' => 'credential-rotation-account', 'enabled' => true, 'free_quota' => 1_000,
        ], ['appid' => 'old-app-id', 'secret' => 'old-secret'], 4);
        $accounts->markVerified((int) $account['id'], 4);
        $accounts->setDispatch((int) $account['id'], true, 4);
        $before = DB::table('lgt_provider_accounts')->where('id', $account['id'])->first();

        $updated = $accounts->update((int) $account['id'], ['enabled' => true], [
            'appid' => 'new-app-id', 'secret' => 'new-secret',
        ], 5);
        $after = DB::table('lgt_provider_accounts')->where('id', $account['id'])->first();

        self::assertFalse((bool) $updated['dispatch_enabled']);
        self::assertNull($updated['verified_at']);
        self::assertSame((int) $before->credential_revision + 1, (int) $after->credential_revision);
        self::assertNotSame($before->credentials_ciphertext, $after->credentials_ciphertext);
        self::assertSame(['appid' => 'new-app-id', 'secret' => 'new-secret'], $accounts->credentials($after));
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
                return new TranslationProviderResponse(true, ['Vérification LONGOS : ' . $request->segments[0]], 'provider-probe', null, hash('sha256', 'probe'), 1);
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
        self::assertGreaterThan(19, (int) DB::table('lgt_usage_months')->value('committed_units'));
    }

    public function test_provider_verification_refuses_an_account_that_corrupts_protected_markers(): void
    {
        $accounts = new ProviderAccountService;
        $account  = $accounts->save([
            'provider' => 'baidu', 'billing_identity' => 'token-incompatible-sandbox', 'enabled' => true, 'free_quota' => 1000,
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
                return new TranslationProviderResponse(true, ['Vérification LONGOS sans marqueur'], 'provider-probe', null, hash('sha256', 'probe'), 1);
            }

            public function classifyFailure(\Throwable|TranslationProviderResponse $failure): array
            {
                return ['kind' => 'definite_failure', 'code' => 'TEST', 'definitely_unbilled' => true];
            }
        };

        try {
            (new ProviderVerificationService($accounts, new ProviderRegistry(['baidu' => $provider]), new UsageLedger))->verify((int) $account['id'], 8);
            self::fail('A provider that drops protected markers must not become dispatchable.');
        } catch (RuntimeException $exception) {
            self::assertSame('PROVIDER_TOKEN_INCOMPATIBLE', $exception->getMessage());
        }

        self::assertNull(DB::table('lgt_provider_accounts')->where('id', $account['id'])->value('verified_at'));
        self::assertSame(UsageLedger::COMMITTED, DB::table('lgt_attempts')->where('provider_account_id', $account['id'])->value('state'));
    }

    public function test_verification_rejects_unreadable_credentials_before_reserving_or_contacting_a_provider(): void
    {
        $accounts = new ProviderAccountService;
        $account  = $accounts->save([
            'provider'   => 'baidu', 'billing_identity' => 'unreadable-verification', 'enabled' => true,
            'free_quota' => 100, 'soft_cap' => 100,
        ], ['appid' => 'verification-app', 'secret' => 'verification-secret'], 18);
        DB::table('lgt_provider_accounts')->where('id', $account['id'])->update(['credentials_ciphertext' => 'broken-ciphertext']);
        $provider = new class implements TranslationProviderInterface
        {
            public int $calls = 0;

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
                $this->calls++;

                return new TranslationProviderResponse(true, ['unexpected'], 'unexpected', null, null, 1);
            }

            public function classifyFailure(\Throwable|TranslationProviderResponse $failure): array
            {
                return ['kind' => 'definite_failure', 'code' => 'TEST', 'definitely_unbilled' => true];
            }
        };

        try {
            (new ProviderVerificationService($accounts, new ProviderRegistry(['baidu' => $provider]), new UsageLedger))->verify((int) $account['id'], 18);
            self::fail('Unreadable local credentials must fail before a controlled probe is reserved.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('PROVIDER_CREDENTIALS_UNREADABLE', $exception->getMessage());
        }

        self::assertSame(0, $provider->calls);
        self::assertSame(0, DB::table('lgt_attempts')->count());
    }

    public function test_missing_dispatchable_account_pauses_without_losing_or_requeuing_the_claimed_item(): void
    {
        (new TranslationSettings)->save(['mode' => TranslationSettings::MODE_FREE_ONLY, 'enabled_locales' => ['fr']]);
        $entryId = DB::table('lgt_entries')->insertGetId([
            'content_key'   => hash('sha256', 'queued-product'), 'kind' => 'product', 'entity_id' => 98, 'field' => 'name',
            'source_locale' => 'en', 'source_format' => 'plain', 'source_text' => 'Queued case', 'source_hash' => ContentScanner::sourceHash('Queued case'),
            'is_public'     => true, 'is_tombstoned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $jobId = DB::table('lgt_jobs')->insertGetId([
            'public_id' => '7924b49f-e6ce-4eb6-a3be-bfab91011111', 'status' => 'queued', 'mode' => TranslationSettings::MODE_FREE_ONLY,
            'scope'     => json_encode(['product']), 'target_locales' => json_encode(['fr']), 'created_at' => now(), 'updated_at' => now(),
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
        $plan = json_decode((string) DB::table('lgt_jobs')->where('id', $jobId)->value('plan'), true);
        self::assertSame('NO_VERIFIED_DISPATCH_ACCOUNT', $plan['pause']['code'] ?? null);
    }

    public function test_unsafe_source_is_rejected_before_a_provider_request_is_reserved(): void
    {
        (new TranslationSettings)->save(['mode' => TranslationSettings::MODE_FREE_ONLY, 'enabled_locales' => ['fr']]);
        $accounts = new ProviderAccountService;
        $account = $accounts->save([
            'provider' => 'baidu', 'billing_identity' => 'source-preflight-account', 'enabled' => true,
            'free_quota' => 1_000, 'soft_cap' => 1_000,
        ], ['appid' => 'source-preflight-app', 'secret' => 'source-preflight-secret'], 11);
        $accounts->markVerified((int) $account['id'], 11);
        $accounts->setDispatch((int) $account['id'], true, 11);
        $entryId = $this->queueSingleTranslationItem('unsafe-source-preflight', '<script>alert(1)</script>', 'a09d2c91-7d2a-4a1b-a5ac-120700000001');
        $jobId = (int) DB::table('lgt_job_items')->where('entry_id', $entryId)->value('job_id');
        $provider = new class implements TranslationProviderInterface
        {
            public int $calls = 0;

            public function id(): string { return 'baidu'; }
            public function validateCredentialShape(array $credentials): void {}
            public function supports(string $sourceLocale, string $targetLocale): bool { return true; }
            public function buildBatches(array $segments, string $sourceLocale, string $targetLocale): array { return [$segments]; }
            public function send(TranslationProviderRequest $request, array $credentials): TranslationProviderResponse
            {
                $this->calls++;

                return new TranslationProviderResponse(true, ['should not be called'], 'unexpected', null, null, 1);
            }
            public function classifyFailure(\Throwable|TranslationProviderResponse $failure): array
            {
                return ['kind' => 'definite_failure', 'code' => 'TEST', 'definitely_unbilled' => true];
            }
        };
        $service = new TranslationJobService(new ContentScanner, new TranslationSettings, $accounts, new ProviderRegistry(['baidu' => $provider]), new UsageLedger, new TranslationLibrary);
        $service->process($jobId, 1, 'f152f01b-4bfa-4ed7-bf63-120700000001');

        self::assertSame(0, $provider->calls);
        self::assertSame(0, DB::table('lgt_attempts')->count());
        self::assertSame('SOURCE_TEXT_REJECTED', DB::table('lgt_job_items')->where('entry_id', $entryId)->value('failure_code'));
    }

    public function test_free_quota_pause_records_an_operator_actionable_reason(): void
    {
        (new TranslationSettings)->save(['mode' => TranslationSettings::MODE_FREE_ONLY, 'enabled_locales' => ['fr']]);
        $accounts = new ProviderAccountService;
        $account = $accounts->save([
            'provider' => 'baidu', 'billing_identity' => 'capacity-pause-account', 'enabled' => true,
            'free_quota' => 5, 'soft_cap' => 5,
        ], ['appid' => 'capacity-pause-app', 'secret' => 'capacity-pause-secret'], 11);
        $accounts->markVerified((int) $account['id'], 11);
        $accounts->setDispatch((int) $account['id'], true, 11);
        $entryId = $this->queueSingleTranslationItem('capacity-pause', 'A title that exceeds the remaining free quota.', 'e5c4e0bd-1438-451d-a2e2-120700000002');
        $jobId = (int) DB::table('lgt_job_items')->where('entry_id', $entryId)->value('job_id');
        $service = new TranslationJobService(new ContentScanner, new TranslationSettings, $accounts, new ProviderRegistry, new UsageLedger, new TranslationLibrary);
        $service->process($jobId, 1, '2869c2bb-f609-48a3-b001-120700000002');

        self::assertSame('paused', DB::table('lgt_jobs')->where('id', $jobId)->value('status'));
        $plan = json_decode((string) DB::table('lgt_jobs')->where('id', $jobId)->value('plan'), true);
        self::assertSame('FREE_QUOTA_EXHAUSTED', $plan['pause']['code'] ?? null);
        self::assertSame('ready', DB::table('lgt_job_items')->where('entry_id', $entryId)->value('status'));
    }

    public function test_failed_worker_can_only_finish_claims_owned_by_its_run_token(): void
    {
        $jobId = DB::table('lgt_jobs')->insertGetId([
            'public_id' => '40ee7d26-8c6f-40f4-9159-5fb9423b2222', 'status' => 'paused', 'mode' => TranslationSettings::MODE_FREE_ONLY,
            'scope'     => json_encode(['product']), 'target_locales' => json_encode(['fr']), 'created_at' => now(), 'updated_at' => now(),
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
        $account  = $accounts->save([
            'provider' => 'baidu', 'billing_identity' => 'queue-success', 'enabled' => true, 'free_quota' => 1_000, 'soft_cap' => 1_000,
        ], ['appid' => 'queue-app', 'secret' => 'queue-secret'], 11);
        $accounts->markVerified((int) $account['id'], 11);
        $accounts->setDispatch((int) $account['id'], true, 11);

        $entryId = DB::table('lgt_entries')->insertGetId([
            'content_key'   => hash('sha256', 'completed-product'), 'kind' => 'product', 'entity_id' => 99, 'field' => 'name',
            'source_locale' => 'en', 'source_format' => 'plain', 'source_text' => 'Protected case', 'source_hash' => ContentScanner::sourceHash('Protected case'),
            'is_public'     => true, 'is_tombstoned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $jobId = DB::table('lgt_jobs')->insertGetId([
            'public_id' => '4d97dbfa-26de-4859-a75f-d0713d2a3333', 'status' => 'queued', 'mode' => TranslationSettings::MODE_FREE_ONLY,
            'scope'     => json_encode(['product']), 'target_locales' => json_encode(['fr']), 'total_items' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('lgt_job_items')->insert([
            'job_id' => $jobId, 'entry_id' => $entryId, 'target_locale' => 'fr', 'source_hash' => ContentScanner::sourceHash('Protected case'),
            'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $provider = new class implements TranslationProviderInterface
        {
            public function id(): string
            {
                return 'baidu';
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
                return new TranslationProviderResponse(true, ['Étui protégé'], 'queue-result', null, hash('sha256', 'queue-result'), 1);
            }

            public function classifyFailure(\Throwable|TranslationProviderResponse $failure): array
            {
                return ['kind' => 'definite', 'code' => 'TEST', 'definitely_unbilled' => true];
            }
        };
        $service = new TranslationJobService(new ContentScanner, new TranslationSettings, $accounts, new ProviderRegistry(['baidu' => $provider]), new UsageLedger, new TranslationLibrary);
        $service->process($jobId, 1, 'e2ec17a3-7321-447b-a010-8b42a3550001');

        self::assertSame('completed', DB::table('lgt_jobs')->where('id', $jobId)->value('status'));
        self::assertSame('completed', DB::table('lgt_job_items')->where('job_id', $jobId)->value('status'));
        self::assertNull(DB::table('lgt_job_items')->where('job_id', $jobId)->value('claim_token'));
        self::assertSame('Étui protégé', DB::table('lgt_translations')->where('entry_id', $entryId)->value('text'));
        self::assertSame(TranslationLibrary::APPROVED, DB::table('lgt_translations')->where('entry_id', $entryId)->value('status'));
        self::assertSame(1, DB::table('lgt_publications')->where('locale', 'fr')->where('state', 'published')->count());
        self::assertTrue((bool) (new TranslationSettings)->all()['widget']['enabled']);
        $plan = json_decode((string) DB::table('lgt_jobs')->where('id', $jobId)->value('plan'), true);
        self::assertSame('published', $plan['auto_publication']['state']);
    }

    public function test_local_free_quota_exhaustion_fails_over_to_the_next_verified_provider_in_priority_order(): void
    {
        (new TranslationSettings)->save(['mode' => TranslationSettings::MODE_FREE_ONLY, 'enabled_locales' => ['fr']]);
        $accounts = new ProviderAccountService;
        $primary  = $accounts->save([
            'provider'   => 'baidu', 'billing_identity' => 'primary-cap-exhausted', 'enabled' => true,
            'free_quota' => 10, 'soft_cap' => 10, 'failover_priority' => 10,
        ], ['appid' => 'primary-app', 'secret' => 'primary-secret'], 14);
        $secondary = $accounts->save([
            'provider'   => 'aliyun', 'billing_identity' => 'secondary-free-capacity', 'enabled' => true,
            'free_quota' => 100, 'soft_cap' => 100, 'failover_priority' => 20,
        ], ['access_key_id' => 'secondary-key', 'access_key_secret' => 'secondary-secret'], 14);
        foreach ([$primary, $secondary] as $account) {
            $accounts->markVerified((int) $account['id'], 14);
            $accounts->setDispatch((int) $account['id'], true, 14);
        }
        // Simulate capacity already committed/reserved by another task. The
        // primary provider must not receive this field at all.
        (new UsageLedger)->reserve((int) $primary['id'], 10);

        $entryId         = $this->queueSingleTranslationItem('local-failover', 'Free quota fallback', '9f6e93f8-b9a0-4abd-b6db-000000000001');
        $primaryProvider = new class implements TranslationProviderInterface
        {
            public int $calls = 0;

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
                $this->calls++;

                throw new RuntimeException('PRIMARY_MUST_NOT_BE_CALLED');
            }

            public function classifyFailure(\Throwable|TranslationProviderResponse $failure): array
            {
                return ['kind' => 'definite_failure', 'code' => 'TEST', 'definitely_unbilled' => true];
            }
        };
        $secondaryProvider = new class implements TranslationProviderInterface
        {
            public int $calls = 0;

            public function id(): string
            {
                return 'aliyun';
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
                $this->calls++;

                return new TranslationProviderResponse(true, ['Relais de quota'], 'secondary-request', null, hash('sha256', 'secondary'), 1);
            }

            public function classifyFailure(\Throwable|TranslationProviderResponse $failure): array
            {
                return ['kind' => 'definite_failure', 'code' => 'TEST', 'definitely_unbilled' => true];
            }
        };
        $service = new TranslationJobService(new ContentScanner, new TranslationSettings, $accounts, new ProviderRegistry(['baidu' => $primaryProvider, 'aliyun' => $secondaryProvider]), new UsageLedger, new TranslationLibrary);
        $service->process((int) DB::table('lgt_job_items')->where('entry_id', $entryId)->value('job_id'), 1, '9f6e93f8-b9a0-4abd-b6db-000000000002');

        self::assertSame(0, $primaryProvider->calls);
        self::assertSame(1, $secondaryProvider->calls);
        self::assertSame('aliyun', DB::table('lgt_translations')->where('entry_id', $entryId)->value('provider'));
        self::assertSame('completed', DB::table('lgt_job_items')->where('entry_id', $entryId)->value('status'));
    }

    public function test_explicit_provider_balance_exhaustion_fails_over_without_replaying_an_uncertain_request(): void
    {
        (new TranslationSettings)->save(['mode' => TranslationSettings::MODE_FREE_ONLY, 'enabled_locales' => ['fr']]);
        $accounts = new ProviderAccountService;
        $primary  = $accounts->save([
            'provider'   => 'baidu', 'billing_identity' => 'primary-provider-balance', 'enabled' => true,
            'free_quota' => 100, 'soft_cap' => 100, 'failover_priority' => 10,
        ], ['appid' => 'balance-app', 'secret' => 'balance-secret'], 15);
        $secondary = $accounts->save([
            'provider'   => 'volcengine', 'billing_identity' => 'secondary-provider-balance', 'enabled' => true,
            'free_quota' => 100, 'soft_cap' => 100, 'failover_priority' => 20,
        ], ['access_key_id' => 'fallback-key', 'secret_access_key' => 'fallback-secret'], 15);
        foreach ([$primary, $secondary] as $account) {
            $accounts->markVerified((int) $account['id'], 15);
            $accounts->setDispatch((int) $account['id'], true, 15);
        }

        $entryId         = $this->queueSingleTranslationItem('provider-failover', 'Provider balance fallback', 'c4a2c70d-7020-4d0d-8d14-000000000001');
        $primaryProvider = new class implements TranslationProviderInterface
        {
            public int $calls = 0;

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
                $this->calls++;

                return new TranslationProviderResponse(false, [], 'balance-request', 'BALANCE_EXHAUSTED', hash('sha256', 'balance'), 1);
            }

            public function classifyFailure(\Throwable|TranslationProviderResponse $failure): array
            {
                return ['kind' => 'definite_failure', 'code' => 'BALANCE_EXHAUSTED', 'definitely_unbilled' => true];
            }
        };
        $secondaryProvider = new class implements TranslationProviderInterface
        {
            public int $calls = 0;

            public function id(): string
            {
                return 'volcengine';
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
                $this->calls++;

                return new TranslationProviderResponse(true, ['Relais fournisseur'], 'fallback-request', null, hash('sha256', 'fallback'), 1);
            }

            public function classifyFailure(\Throwable|TranslationProviderResponse $failure): array
            {
                return ['kind' => 'definite_failure', 'code' => 'TEST', 'definitely_unbilled' => true];
            }
        };
        $service = new TranslationJobService(new ContentScanner, new TranslationSettings, $accounts, new ProviderRegistry(['baidu' => $primaryProvider, 'volcengine' => $secondaryProvider]), new UsageLedger, new TranslationLibrary);
        $service->process((int) DB::table('lgt_job_items')->where('entry_id', $entryId)->value('job_id'), 1, 'c4a2c70d-7020-4d0d-8d14-000000000002');

        self::assertSame(1, $primaryProvider->calls);
        self::assertSame(1, $secondaryProvider->calls);
        self::assertSame(UsageLedger::DEFINITE_FAILED, DB::table('lgt_attempts')->where('provider_account_id', $primary['id'])->where('job_item_id', DB::table('lgt_job_items')->where('entry_id', $entryId)->value('id'))->value('state'));
        self::assertSame(UsageLedger::COMMITTED, DB::table('lgt_attempts')->where('provider_account_id', $secondary['id'])->where('job_item_id', DB::table('lgt_job_items')->where('entry_id', $entryId)->value('id'))->value('state'));
        self::assertSame('volcengine', DB::table('lgt_translations')->where('entry_id', $entryId)->value('provider'));
        self::assertFalse((bool) DB::table('lgt_provider_accounts')->where('id', $primary['id'])->value('dispatch_enabled'));
        self::assertTrue((bool) DB::table('lgt_provider_accounts')->where('id', $secondary['id'])->value('dispatch_enabled'));
    }

    public function test_unreadable_local_credentials_fail_over_without_sending_to_the_broken_account(): void
    {
        (new TranslationSettings)->save(['mode' => TranslationSettings::MODE_FREE_ONLY, 'enabled_locales' => ['fr']]);
        $accounts = new ProviderAccountService;
        $primary  = $accounts->save([
            'provider'   => 'baidu', 'billing_identity' => 'unreadable-primary', 'enabled' => true,
            'free_quota' => 100, 'soft_cap' => 100, 'failover_priority' => 10,
        ], ['appid' => 'primary-app', 'secret' => 'primary-secret'], 17);
        $secondary = $accounts->save([
            'provider'   => 'aliyun', 'billing_identity' => 'healthy-secondary', 'enabled' => true,
            'free_quota' => 100, 'soft_cap' => 100, 'failover_priority' => 20,
        ], ['access_key_id' => 'secondary-key', 'access_key_secret' => 'secondary-secret'], 17);
        foreach ([$primary, $secondary] as $account) {
            $accounts->markVerified((int) $account['id'], 17);
            $accounts->setDispatch((int) $account['id'], true, 17);
        }
        // Simulates an old/interrupted credential write. The runtime must
        // prove the failure locally, release its reservation and use the next
        // verified account without calling the broken provider.
        DB::table('lgt_provider_accounts')->where('id', $primary['id'])->update(['credentials_ciphertext' => 'not-a-laravel-ciphertext']);

        $entryId         = $this->queueSingleTranslationItem('unreadable-credential-failover', 'Safe credential fallback', '740c1c0c-2ac1-4fe5-97a2-000000000001');
        $primaryProvider = new class implements TranslationProviderInterface
        {
            public int $calls = 0;

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
                $this->calls++;

                return new TranslationProviderResponse(true, ['must not be used'], 'invalid-primary', null, null, 1);
            }

            public function classifyFailure(\Throwable|TranslationProviderResponse $failure): array
            {
                return ['kind' => 'definite_failure', 'code' => 'AUTH', 'definitely_unbilled' => true];
            }
        };
        $secondaryProvider = new class implements TranslationProviderInterface
        {
            public int $calls = 0;

            public function id(): string
            {
                return 'aliyun';
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
                $this->calls++;

                return new TranslationProviderResponse(true, ['Relais local sûr'], 'healthy-secondary', null, hash('sha256', 'healthy-secondary'), 1);
            }

            public function classifyFailure(\Throwable|TranslationProviderResponse $failure): array
            {
                return ['kind' => 'definite_failure', 'code' => 'TEST', 'definitely_unbilled' => true];
            }
        };
        $service = new TranslationJobService(new ContentScanner, new TranslationSettings, $accounts, new ProviderRegistry(['baidu' => $primaryProvider, 'aliyun' => $secondaryProvider]), new UsageLedger, new TranslationLibrary);
        $service->process((int) DB::table('lgt_job_items')->where('entry_id', $entryId)->value('job_id'), 1, '740c1c0c-2ac1-4fe5-97a2-000000000002');

        self::assertSame(0, $primaryProvider->calls);
        self::assertSame(1, $secondaryProvider->calls);
        self::assertSame(UsageLedger::DEFINITE_FAILED, DB::table('lgt_attempts')->where('provider_account_id', $primary['id'])->value('state'));
        self::assertFalse((bool) DB::table('lgt_provider_accounts')->where('id', $primary['id'])->value('dispatch_enabled'));
        self::assertSame('aliyun', DB::table('lgt_translations')->where('entry_id', $entryId)->value('provider'));
    }

    public function test_safe_retry_requeues_only_definitely_unbilled_failures(): void
    {
        Queue::fake();
        $accounts = new ProviderAccountService;
        $account  = $accounts->save([
            'provider'   => 'baidu', 'billing_identity' => 'safe-retry-ledger', 'enabled' => true,
            'free_quota' => 100, 'soft_cap' => 100,
        ], ['appid' => 'retry-app', 'secret' => 'retry-secret'], 19);
        $accounts->markVerified((int) $account['id'], 19);
        $accounts->setDispatch((int) $account['id'], true, 19);

        $safeEntry   = $this->queueSingleTranslationItem('safe-retry-entry', 'Safe retry entry', 'bda5881d-7122-4b45-a5ea-000000000001');
        $jobId       = (int) DB::table('lgt_job_items')->where('entry_id', $safeEntry)->value('job_id');
        $safeItem    = (int) DB::table('lgt_job_items')->where('entry_id', $safeEntry)->value('id');
        $secondEntry = DB::table('lgt_entries')->insertGetId([
            'content_key'   => hash('sha256', 'uncertain-retry-entry'), 'kind' => 'product', 'entity_id' => 99881, 'field' => 'name',
            'source_locale' => 'en', 'source_format' => 'plain', 'source_text' => 'Uncertain retry entry', 'source_hash' => ContentScanner::sourceHash('Uncertain retry entry'),
            'is_public'     => true, 'is_tombstoned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $uncertainItem = DB::table('lgt_job_items')->insertGetId([
            'job_id' => $jobId, 'entry_id' => $secondEntry, 'target_locale' => 'fr', 'source_hash' => ContentScanner::sourceHash('Uncertain retry entry'),
            'status' => 'failed', 'failure_code' => 'WORKER_EXCEPTION', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('lgt_jobs')->where('id', $jobId)->update(['status' => 'partial_failed', 'total_items' => 2, 'completed_items' => 2, 'failed_items' => 2, 'finished_at' => now()]);

        $ledger      = new UsageLedger;
        $safeAttempt = $ledger->reserve((int) $account['id'], 5, $safeItem);
        $ledger->markSending($safeAttempt['id']);
        $ledger->definiteFailure($safeAttempt['id'], 'AUTH');
        DB::table('lgt_job_items')->where('id', $safeItem)->update(['status' => 'failed', 'failure_code' => 'AUTH', 'attempt_id' => $safeAttempt['id'], 'completed_at' => now()]);
        $uncertainAttempt = $ledger->reserve((int) $account['id'], 5, $uncertainItem);
        $ledger->markSending($uncertainAttempt['id']);
        $ledger->uncertain($uncertainAttempt['id'], 'NETWORK_AFTER_SEND');
        DB::table('lgt_job_items')->where('id', $uncertainItem)->update(['attempt_id' => $uncertainAttempt['id']]);

        $service = new TranslationJobService(new ContentScanner, new TranslationSettings, $accounts, new ProviderRegistry, $ledger, new TranslationLibrary);
        self::assertSame(1, $service->retryableFailureCount($jobId));
        self::assertSame(1, $service->retryDefiniteFailures($jobId));

        Queue::assertPushed(ProcessTranslationJob::class, fn (ProcessTranslationJob $job): bool => $job->translationJobId === $jobId);
        self::assertSame('ready', DB::table('lgt_job_items')->where('id', $safeItem)->value('status'));
        self::assertNull(DB::table('lgt_job_items')->where('id', $safeItem)->value('attempt_id'));
        self::assertSame('failed', DB::table('lgt_job_items')->where('id', $uncertainItem)->value('status'));
        self::assertSame('WORKER_EXCEPTION', DB::table('lgt_job_items')->where('id', $uncertainItem)->value('failure_code'));
        self::assertSame('queued', DB::table('lgt_jobs')->where('id', $jobId)->value('status'));
        self::assertSame(1, (int) DB::table('lgt_jobs')->where('id', $jobId)->value('completed_items'));
        self::assertSame(1, (int) DB::table('lgt_jobs')->where('id', $jobId)->value('failed_items'));
    }

    public function test_failure_code_backfill_recovers_a_safe_historical_reason_from_the_ledger(): void
    {
        $accounts = new ProviderAccountService;
        $account  = $accounts->save([
            'provider'   => 'baidu', 'billing_identity' => 'historical-failure-backfill', 'enabled' => true,
            'free_quota' => 100, 'soft_cap' => 100,
        ], ['appid' => 'history-app', 'secret' => 'history-secret'], 21);
        $accounts->markVerified((int) $account['id'], 21);
        $accounts->setDispatch((int) $account['id'], true, 21);

        $entryId = $this->queueSingleTranslationItem('historical-failure-backfill', 'Historical failure', 'f4b4ae14-234f-4590-83da-000000000001');
        $itemId  = (int) DB::table('lgt_job_items')->where('entry_id', $entryId)->value('id');
        $ledger  = new UsageLedger;
        $attempt = $ledger->reserve((int) $account['id'], 5, $itemId);
        $ledger->markSending($attempt['id']);
        $ledger->definiteFailure($attempt['id'], 'AUTH');
        DB::table('lgt_job_items')->where('id', $itemId)->update([
            'status'       => 'failed',
            'attempt_id'   => $attempt['id'],
            'failure_code' => null,
            'completed_at' => now(),
        ]);

        $migration = require base_path('plugins/LongosGlobalTranslate/Migrations/2026_11_04_000007_backfill_job_item_failure_codes.php');
        $migration->up();

        self::assertSame('AUTH', DB::table('lgt_job_items')->where('id', $itemId)->value('failure_code'));
    }

    public function test_lost_worker_releases_only_a_pre_send_reservation_and_quarantines_a_sending_attempt(): void
    {
        $accounts = new ProviderAccountService;
        $account  = $accounts->save([
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

    private function queueSingleTranslationItem(string $key, string $source, string $jobPublicId): int
    {
        $hash    = ContentScanner::sourceHash($source);
        $entryId = DB::table('lgt_entries')->insertGetId([
            'content_key'   => hash('sha256', $key), 'kind' => 'product', 'entity_id' => random_int(10_000, 99_999), 'field' => 'name',
            'source_locale' => 'en', 'source_format' => 'plain', 'source_text' => $source, 'source_hash' => $hash,
            'is_public'     => true, 'is_tombstoned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $jobId = DB::table('lgt_jobs')->insertGetId([
            'public_id' => $jobPublicId, 'status' => 'queued', 'mode' => TranslationSettings::MODE_FREE_ONLY,
            'scope'     => json_encode(['product']), 'target_locales' => json_encode(['fr']), 'total_items' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('lgt_job_items')->insert([
            'job_id' => $jobId, 'entry_id' => $entryId, 'target_locale' => 'fr', 'source_hash' => $hash,
            'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $entryId;
    }
}
