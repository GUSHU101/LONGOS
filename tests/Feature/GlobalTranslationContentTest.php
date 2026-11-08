<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Plugin\LongosGlobalTranslate\Services\CommerceUiCatalog;
use Plugin\LongosGlobalTranslate\Services\ContentScanner;
use Plugin\LongosGlobalTranslate\Services\DictionaryService;

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
        $priority = require base_path('plugins/LongosGlobalTranslate/Migrations/2026_11_03_000005_add_provider_failover_priority.php');
        $priority->up();
        $failureCodes = require base_path('plugins/LongosGlobalTranslate/Migrations/2026_11_04_000006_add_job_item_failure_codes.php');
        $failureCodes->up();
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
        self::assertSame(2000, $scanner->scan(['product'])['found']);
        self::assertSame(0, $scanner->scan(['product'])['changed']);
        self::assertSame(2000, DB::table('lgt_entries')->count());
        self::assertFalse(DB::table('lgt_entries')->where('entity_id', 1001)->exists());
    }

    public function test_source_change_only_invalidates_its_field_and_retains_locked_text(): void
    {
        DB::table('products')->insert(['id' => 1, 'active' => true]);
        DB::table('product_descriptions')->insert(['product_id' => 1, 'locale' => 'en', 'name' => 'Case', 'content' => 'Body']);
        $scanner = new ContentScanner;
        $scanner->scan(['product']);
        foreach (DB::table('lgt_entries')->get() as $entry) {
            DB::table('lgt_translations')->insert(['entry_id' => $entry->id, 'locale' => 'fr', 'source_hash' => $entry->source_hash, 'text' => 'Human text', 'locked' => true, 'status' => 'approved']);
        }
        DB::table('product_descriptions')->where('product_id', 1)->update(['name' => 'New case']);
        self::assertSame(1, $scanner->scan(['product'])['changed']);
        self::assertSame(1, DB::table('lgt_translations')->where('status', 'stale')->count());
        self::assertSame(1, DB::table('lgt_translations')->where('status', 'approved')->count());
        self::assertSame(1, DB::table('lgt_translations')->where('locked', true)->where('text', 'Human text')->count());
    }

    public function test_commerce_scope_indexes_only_the_finite_static_checkout_and_account_catalog(): void
    {
        $report = (new ContentScanner)->scan([CommerceUiCatalog::KIND]);

        self::assertSame([CommerceUiCatalog::KIND], $report['supported_sources']);
        self::assertSame(count(CommerceUiCatalog::entries()), $report['found']);
        self::assertSame(count(CommerceUiCatalog::entries()), DB::table('lgt_entries')
            ->where('kind', CommerceUiCatalog::KIND)
            ->where('entity_id', CommerceUiCatalog::ENTITY_ID)
            ->count());
        self::assertFalse(DB::table('lgt_entries')->where('source_text', 'like', '%@%')->exists());
        // A static security sentence may mention a card number, but no
        // rendered token/value template is ever admitted to the catalog.
        self::assertFalse(DB::table('lgt_entries')->where('source_text', 'like', '%:order%')->exists());
        self::assertFalse(DB::table('lgt_entries')->where('source_text', 'like', '%:amount%')->exists());
    }

    public function test_default_global_scan_always_includes_the_value_free_storefront_interface_catalog(): void
    {
        $report = (new ContentScanner)->scan();

        self::assertContains(CommerceUiCatalog::KIND, $report['supported_sources']);
        self::assertSame(count(CommerceUiCatalog::entries()), DB::table('lgt_entries')
            ->where('kind', CommerceUiCatalog::KIND)
            ->where('entity_id', CommerceUiCatalog::ENTITY_ID)
            ->count());
        self::assertFalse(DB::table('lgt_entries')->where('source_text', 'like', '%@%')->exists());
    }

    public function test_commerce_catalog_covers_default_storefront_surfaces_without_dynamic_templates(): void
    {
        $catalog = CommerceUiCatalog::entries();

        // Navigation, catalogue, cart, checkout, account, recovery, hosted
        // payment and pagination are all sourced from the default public
        // language packs. This guards against silently regressing back to a
        // checkout-only UI dictionary.
        self::assertGreaterThan(750, count($catalog));
        foreach ([
            'Open menu', 'Search products', 'Add To Cart', 'Your shopping cart is empty',
            'Back to cart', 'Secure checkout', 'Return to order', 'Previous', 'Loading...',
            'Order Details', 'Store policy', 'The website is under maintenance, please visit later!',
        ] as $source) {
            self::assertContains($source, $catalog);
        }

        foreach ($catalog as $source) {
            self::assertSame(0, preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $source));
            self::assertStringNotContainsString('|', $source);
            self::assertDoesNotMatchRegularExpression('~(?:https?://|mailto:|\\b[\\w.+-]+@[\\w.-]+\\.[A-Za-z]{2,}\\b)~iu', $source);
        }
    }

    public function test_every_eligible_default_theme_ui_label_is_covered_by_the_interface_catalog(): void
    {
        app()->setLocale('en');
        $catalog  = array_fill_keys(CommerceUiCatalog::entries(), true);
        $missing  = [];
        $root     = base_path('themes/default');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            // Transactional email is rendered server-side and may contain
            // customer and order values. It is deliberately out of the
            // browser-only public dictionary contract.
            if (str_starts_with($relative, 'mails/') || $relative === 'layout/mail.blade.php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            preg_match_all('/(?:__|@lang)\\(\\s*[\'\"]([^\'\"]+)[\'\"]/', $contents, $matches);
            foreach (array_unique($matches[1] ?? []) as $key) {
                // Concatenated/runtime keys cannot be proven static from a
                // template scan, so they remain out of this assertion.
                if (str_ends_with($key, '.')) {
                    continue;
                }
                if (preg_match('/(?:__|@lang)\\(\\s*[\'\"]' . preg_quote($key, '/') . '[\'\"]\\s*\\./', $contents) === 1) {
                    continue;
                }
                $source = (string) __($key);
                if (! CommerceUiCatalog::acceptsStaticSource($source)) {
                    continue;
                }
                $source = CommerceUiCatalog::canonicalSource($source);
                if (! isset($catalog[$source])) {
                    $missing[] = $relative . ' => ' . $key . ' => ' . $source;
                }
            }
        }

        self::assertSame([], $missing, "Missing public interface sources:\n" . implode("\n", $missing));
    }

    public function test_every_eligible_public_plugin_view_label_is_covered_by_the_interface_catalog(): void
    {
        $catalog = array_fill_keys(CommerceUiCatalog::entries(), true);
        $sources = [
            'LongosPlatformPay::common'     => 'plugins/LongosPlatformPay/Lang/en/common.php',
            'LongosLiveChat::chat'          => 'plugins/LongosLiveChat/Lang/en/chat.php',
            'LongosProductReviews::reviews' => 'plugins/LongosProductReviews/Lang/en/reviews.php',
            'Paypal::checkout'              => 'plugins/Paypal/Lang/en/checkout.php',
            'Stripe::common'                => 'plugins/Stripe/Lang/en/common.php',
        ];
        $roots = [
            'plugins/LongosPlatformPay/Views/checkout',
            'plugins/LongosLiveChat/Views/shop',
            'plugins/LongosProductReviews/Views/shop',
            'plugins/Paypal/Views/checkout',
            'plugins/Stripe/Views/checkout',
        ];
        $missing = [];

        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root)));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());
                preg_match_all('/(?:__|@lang|trans)\(\s*[\'\"]([^\'\"]+)[\'\"]/', $contents, $matches);
                foreach (array_unique($matches[1] ?? []) as $key) {
                    $prefix = collect(array_keys($sources))->first(static fn (string $candidate): bool => str_starts_with($key, $candidate . '.'));
                    if ($prefix === null) {
                        continue;
                    }

                    $field  = substr($key, strlen($prefix) + 1);
                    $pack   = require base_path($sources[$prefix]);
                    $source = $pack[$field] ?? null;
                    if (! is_string($source) || ! CommerceUiCatalog::acceptsStaticSource($source)) {
                        continue;
                    }

                    $source = CommerceUiCatalog::canonicalSource($source);
                    if (! isset($catalog[$source])) {
                        $missing[] = $file->getPathname() . ' => ' . $key . ' => ' . $source;
                    }
                }
            }
        }

        self::assertSame([], $missing, "Missing public plugin interface sources:\n" . implode("\n", $missing));
    }

    public function test_published_interface_dictionary_is_not_truncated_after_the_first_two_hundred_labels(): void
    {
        (new ContentScanner)->scan([CommerceUiCatalog::KIND]);
        $first = DB::table('lgt_entries')->where('kind', CommerceUiCatalog::KIND)->orderBy('id')->first();
        $last  = DB::table('lgt_entries')->where('kind', CommerceUiCatalog::KIND)->orderByDesc('id')->first();
        self::assertNotNull($first);
        self::assertNotNull($last);
        self::assertGreaterThan(200, (int) $last->id - (int) $first->id);

        $translations = [];
        foreach ([$first, $last] as $index => $entry) {
            $translationId = DB::table('lgt_translations')->insertGetId([
                'entry_id'    => $entry->id,
                'locale'      => 'fr',
                'source_hash' => $entry->source_hash,
                'text'        => 'FR interface ' . $index,
                'status'      => 'approved',
                'locked'      => false,
                'version'     => 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
            $translations[] = ['id' => $translationId, 'entry' => $entry, 'text' => 'FR interface ' . $index];
        }
        $publicationId = DB::table('lgt_publications')->insertGetId([
            'locale'            => 'fr', 'revision' => 1, 'state' => 'published',
            'coverage_snapshot' => json_encode(['approved' => 2]),
            'published_at'      => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($translations as $translation) {
            DB::table('lgt_publication_items')->insert([
                'publication_id'      => $publicationId,
                'translation_id'      => $translation['id'],
                'translation_version' => 1,
                'source_hash'         => $translation['entry']->source_hash,
                'text'                => $translation['text'],
                'created_at'          => now(), 'updated_at' => now(),
            ]);
        }

        $dictionary = (new DictionaryService)->forCommerceInterface('fr');
        self::assertSame('FR interface 1', $dictionary['items'][$last->field]['text']);
    }
}
