<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class StorefrontSeoPresentationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_master_layout_exposes_canonical_and_complete_social_metadata(): void
    {
        $master = $this->read('themes/default/layout/master.blade.php');
        $http   = $this->read('resources/js/http.js');

        self::assertStringContainsString('<link rel="canonical" href="@yield(\'canonical\', storefront_canonical_url())">', $master);
        self::assertStringContainsString('<meta property="og:url" content="@yield(\'canonical\', storefront_canonical_url())">', $master);
        self::assertStringContainsString('<meta property="og:image:alt"', $master);
        self::assertStringContainsString('<meta name="twitter:image"', $master);
        self::assertStringContainsString('<meta name="twitter:image:alt"', $master);
        self::assertStringContainsString("'@type' => 'Organization'", $master);
        self::assertStringContainsString("'@type' => 'WebSite'", $master);
        self::assertStringContainsString('application/ld+json', $master);
        self::assertStringNotContainsString('nginx-alert', $master);
        self::assertStringNotContainsString("__('shop/common.nginx_alert')", $master);
        self::assertStringNotContainsString("includes('nginx')", $http);
        self::assertStringNotContainsString("$('.nginx-alert')", $http);
    }

    public function test_product_schema_and_variant_canonical_share_one_product_identity(): void
    {
        $product = $this->read('themes/default/product/product.blade.php');
        $reviews = $this->read('plugins/LongosProductReviews/Views/shop/section.blade.php');

        self::assertStringContainsString("@section('canonical', shop_route('products.show'", $product);
        self::assertStringContainsString("'@type' => 'Product'", $product);
        self::assertStringContainsString("'@type' => 'Offer'", $product);
        self::assertStringContainsString("'#product'", $product);
        self::assertStringContainsString("'priceCurrency' => strtoupper", $product);
        self::assertStringContainsString("'price_converted'", $product);
        self::assertStringContainsString("'@type' => 'Review'", $product);
        self::assertStringNotContainsString('application/ld+json', $reviews);
        self::assertStringNotContainsString("'@type' => 'Product'", $reviews);
    }

    public function test_tenant_seo_settings_include_safe_301_redirect_management(): void
    {
        $middleware = $this->read('app/Http/Middleware/RedirectLegacyStorefrontPath.php');
        $settings   = $this->read('resources/beike/admin/views/pages/setting/seo.blade.php');

        self::assertStringContainsString('LegacyRedirectService::targetFor', $middleware);
        self::assertStringContainsString('redirect()->away($url, 301)', $middleware);
        self::assertStringContainsString('name="seo_redirects"', $settings);
    }

    public function test_canonical_url_removes_tracking_but_preserves_meaningful_query_state(): void
    {
        $helpers = $this->read('beike/Helpers.php');

        self::assertStringContainsString('function storefront_canonical_url(): string', $helpers);
        self::assertStringContainsString("str_starts_with(\$normalized, 'utm_')", $helpers);
        self::assertStringContainsString("['dclid', 'fbclid', 'gclid', 'iframe'", $helpers);
        self::assertStringContainsString('PHP_QUERY_RFC3986', $helpers);
        self::assertStringNotContainsString("unset(\$query['page'])", $helpers);
    }

    public function test_all_storefront_templates_use_the_saved_meta_keywords_setting(): void
    {
        foreach (['themes/default/layout/master.blade.php', 'themes/default/product/product.blade.php', 'themes/default/category.blade.php', 'themes/default/page_categories/show.blade.php'] as $path) {
            $template = $this->read($path);
            self::assertStringNotContainsString("system_setting('base.meta_keyword')", $template, $path);
        }
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->root . '/' . $path);
    }
}
