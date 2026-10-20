<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LongosProductReviewsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_plugin_identity_routes_and_hooks_follow_the_longos_contract(): void
    {
        $config    = json_decode((string) file_get_contents($this->root . '/plugins/LongosProductReviews/config.json'), true, 512, JSON_THROW_ON_ERROR);
        $bootstrap = (string) file_get_contents($this->root . '/plugins/LongosProductReviews/Bootstrap.php');
        $admin     = (string) file_get_contents($this->root . '/plugins/LongosProductReviews/Routes/admin.php');
        $shop      = (string) file_get_contents($this->root . '/plugins/LongosProductReviews/Routes/shop.php');

        self::assertSame('longos_product_reviews', $config['code']);
        self::assertSame('LONGOS', $config['author']['name']);
        $controller = (string) file_get_contents($this->root . '/beike/Shop/Http/Controllers/ProductController.php');
        $product    = (string) file_get_contents($this->root . '/themes/default/product/product.blade.php');

        self::assertStringContainsString("RuntimeGuard::isActive('longos_product_reviews')", $controller);
        self::assertStringContainsString("'product_reviews' => \$this->productReviews", $controller);
        self::assertStringContainsString("@include('LongosProductReviews::shop.section'", $product);
        self::assertStringContainsString("@hook('product.detail.content.after', \$product)", $product);
        self::assertStringNotContainsString("add_hook_blade('product.detail.content.after'", $bootstrap);
        self::assertStringContainsString("add_hook_blade('layout.header.code'", $bootstrap);
        self::assertStringContainsString("add_hook_blade('admin.product.list.action'", $bootstrap);
        self::assertStringContainsString("add_hook_blade('admin.product.form.sidebar.card.status.after'", $bootstrap);
        self::assertStringContainsString("add_hook_action('admin.product.force_delete_trashed.before'", $bootstrap);
        self::assertStringContainsString("prefix('product-reviews')", $admin);
        self::assertStringContainsString("middleware('throttle:120,1')", $shop);
        self::assertStringContainsString("name('longos_product_reviews.index')", $shop);
        self::assertStringContainsString("name('publication')", $admin);
    }

    public function test_review_storage_is_tenant_scoped_and_storefront_queries_are_published_only(): void
    {
        $service = (string) file_get_contents($this->root . '/plugins/LongosProductReviews/Services/ReviewService.php');
        $request = (string) file_get_contents($this->root . '/plugins/LongosProductReviews/Requests/ReviewRequest.php');

        self::assertStringContainsString("->published()->where('product_id', \$productId)", $service);
        self::assertStringContainsString("tenant_upload_prefix() . '/' . \$relative", $service);
        self::assertStringContainsString("str_starts_with(\$relative, 'reviews/')", $service);
        self::assertStringContainsString('DB::transaction', $service);
        self::assertStringContainsString('function deleteForProducts', $service);
        self::assertMatchesRegularExpression("/'images'\\s*=>\\s*\\['nullable', 'array', 'max:6'\\]/", $request);
        self::assertStringContainsString("'mimes:jpg,jpeg,png,webp'", $request);
        self::assertStringContainsString("'dimensions:max_width=6000,max_height=6000'", $request);
    }

    public function test_admin_and_storefront_views_are_accessible_and_escape_review_content(): void
    {
        $form    = (string) file_get_contents($this->root . '/plugins/LongosProductReviews/Views/admin/form.blade.php');
        $section = (string) file_get_contents($this->root . '/plugins/LongosProductReviews/Views/shop/section.blade.php');
        $cards   = (string) file_get_contents($this->root . '/plugins/LongosProductReviews/Views/shop/cards.blade.php');
        $assets  = (string) file_get_contents($this->root . '/plugins/LongosProductReviews/Views/shop/assets.blade.php');
        $css     = (string) file_get_contents($this->root . '/plugins/LongosProductReviews/Static/css/reviews.css');

        self::assertStringContainsString('enctype="multipart/form-data"', $form);
        self::assertStringContainsString('role="combobox"', $form);
        self::assertStringContainsString('aria-autocomplete="list"', $form);
        self::assertStringContainsString('aria-activedescendant', $form);
        self::assertStringContainsString('id="review-product-status"', $form);
        self::assertStringContainsString('aria-required="true"', $form);
        self::assertStringContainsString('setCustomValidity', $form);
        self::assertStringContainsString('type="radio" name="rating"', $form);
        self::assertStringContainsString('name="images[]"', $form);
        self::assertStringContainsString("@push('footer')", $form);
        self::assertStringContainsString('aria-live="polite"', $section);
        self::assertStringContainsString('data-review-load-more', $section);
        self::assertStringContainsString('data-review-rating', $section);
        self::assertStringContainsString('data-review-sort', $section);
        self::assertStringContainsString('data-review-lightbox', $section);
        self::assertStringContainsString('application/ld+json', $section);
        self::assertStringContainsString("'aggregateRating'", $section);
        self::assertStringContainsString("credentials: 'same-origin'", $section);
        self::assertStringContainsString('data-review-photo', $cards);
        self::assertStringContainsString('{{ $review->body }}', $cards);
        self::assertStringNotContainsString('{!! $review->body !!}', $cards);
        self::assertStringContainsString('id="longos-product-review-styles"', $assets);
        self::assertStringContainsString("base_path('plugins/LongosProductReviews/Static/css/reviews.css')", $assets);
        self::assertStringContainsString('is_file($longosReviewCssPath)', $assets);
        self::assertStringNotContainsString("plugin_asset('longos_product_reviews'", $assets);
        self::assertStringContainsString('min-height: 46px', $css);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        self::assertStringContainsString('.product-review-summary', $css);
    }

    public function test_new_tenants_seed_and_migrate_the_bundled_review_plugin(): void
    {
        $plugins      = (string) file_get_contents($this->root . '/database/seeders/PluginsSeeder.php');
        $settings     = (string) file_get_contents($this->root . '/database/seeders/SettingsSeeder.php');
        $provisioner  = (string) file_get_contents($this->root . '/app/Saas/SiteProvisioner.php');
        $upgrade      = (string) file_get_contents($this->root . '/app/Console/Commands/SaasUpgrade.php');
        $automatic    = (string) file_get_contents($this->root . '/app/Services/DeploymentUpgradeService.php');
        $synchronizer = (string) file_get_contents($this->root . '/app/Services/BundledPluginSynchronizer.php');

        self::assertStringContainsString("'code' => 'longos_product_reviews'", $plugins);
        self::assertStringContainsString("'space' => 'longos_product_reviews'", $settings);
        self::assertStringContainsString('$this->migrateInstalledPlugins();', $provisioner);
        self::assertStringContainsString('PluginRepo::migrateDatabase($plugin);', $provisioner);
        self::assertStringContainsString('BundledPluginSynchronizer::class', $provisioner);
        self::assertStringContainsString('BundledPluginSynchronizer::class', $upgrade);
        self::assertStringContainsString('BundledPluginSynchronizer::class', $automatic);
        self::assertStringContainsString('self::PRODUCT_REVIEWS => [', $synchronizer);
        self::assertStringContainsString("Schema::hasTable('longos_product_reviews')", $synchronizer);
    }

    public function test_plugin_translation_keys_stay_in_sync(): void
    {
        $english     = require $this->root . '/plugins/LongosProductReviews/Lang/en/reviews.php';
        $chinese     = require $this->root . '/plugins/LongosProductReviews/Lang/zh_cn/reviews.php';
        $englishKeys = array_keys($english);
        $chineseKeys = array_keys($chinese);
        sort($englishKeys);
        sort($chineseKeys);

        self::assertSame($englishKeys, $chineseKeys);
        self::assertSame('Verified purchase', $english['verified_purchase']);
        self::assertSame('顾客评论', $chinese['title']);
    }
}
