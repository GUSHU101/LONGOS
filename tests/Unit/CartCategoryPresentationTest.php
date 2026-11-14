<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CartCategoryPresentationTest extends TestCase
{
    public function test_cart_uses_one_responsive_accessible_purchase_surface(): void
    {
        $root   = dirname(__DIR__, 2);
        $cart   = (string) file_get_contents($root . '/themes/default/cart/cart.blade.php');
        $styles = (string) file_get_contents($root . '/resources/beike/shop/default/css/cart-rebuild.scss');
        $app    = (string) file_get_contents($root . '/resources/beike/shop/default/css/app.scss');

        self::assertStringContainsString('class="container cart-page-shell"', $cart);
        self::assertStringContainsString('class="cart-layout"', $cart);
        self::assertStringContainsString('class="cart-line-item"', $cart);
        self::assertStringContainsString('class="quantity-wrap cart-quantity-stepper"', $cart);
        self::assertStringContainsString('class="cart-summary-card"', $cart);
        self::assertStringContainsString('class="cart-checkout-button"', $cart);
        self::assertStringContainsString('aria-live="polite"', $cart);
        self::assertStringContainsString(':for="\'cart-product-\' + product.cart_id"', $cart);
        self::assertStringContainsString('reduce((n,m) => n + m, 0)', $cart);
        self::assertStringNotContainsString('@if (!is_mobile())', $cart);
        self::assertStringNotContainsString('<table class="table">', $cart);
        self::assertStringNotContainsString('btn btn-primary', $cart);

        self::assertStringContainsString('grid-template-columns: minmax(0, 1fr) minmax(320px, 360px);', $styles);
        self::assertStringContainsString('min-height: 44px;', $styles);
        self::assertStringContainsString('--cart-page: #f6f7f5;', $styles);
        self::assertStringContainsString('border-radius: var(--cart-radius-lg);', $styles);
        self::assertStringContainsString('appearance: none;', $styles);
        self::assertStringContainsString('@media (max-width: 575.98px)', $styles);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        self::assertStringContainsString("@import './cart-rebuild';", $app);
    }

    public function test_category_uses_a_full_width_fluid_product_grid_without_filter_sidebar(): void
    {
        $root   = dirname(__DIR__, 2);
        $page   = (string) file_get_contents($root . '/themes/default/category.blade.php');
        $bar    = (string) file_get_contents($root . '/themes/default/shared/filter_bar_block.blade.php');
        $styles = (string) file_get_contents($root . '/resources/beike/shop/default/css/category-rebuild.scss');
        $app    = (string) file_get_contents($root . '/resources/beike/shop/default/css/app.scss');

        self::assertStringContainsString('class="category-layout"', $page);
        self::assertStringContainsString('class="category-product-grid', $page);
        self::assertStringNotContainsString('category-filter-toggle', $page);
        self::assertStringNotContainsString('category-filter-panel', $page);
        self::assertStringNotContainsString('shared.filter_sidebar_block', $page);
        self::assertStringNotContainsString('filter-value-chip', $page);
        self::assertStringNotContainsString('filterAttr', $page);
        self::assertStringNotContainsString('class="mb-filter"', $page);

        self::assertStringContainsString('<fieldset class="style-wrap"', $bar);
        self::assertStringContainsString('class="product-tool-select product-tool-select--sort"', $bar);

        self::assertStringContainsString("body.page-categories .category-layout {\n  display: block;", $styles);
        self::assertStringContainsString('grid-template-columns: repeat(4, minmax(0, 1fr));', $styles);
        self::assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr));', $styles);
        self::assertStringNotContainsString('.category-filter-panel', $styles);
        self::assertStringNotContainsString('.category-filter-overlay', $styles);
        self::assertStringContainsString('overflow-x: clip;', $styles);
        self::assertStringContainsString('class="mobile-paginator"', (string) file_get_contents($root . '/themes/default/shared/pagination/bootstrap-4.blade.php'));
        self::assertStringContainsString('mobile-paginator__direction', $styles);
        self::assertStringContainsString('grid-template-columns: minmax(0, 1fr) 104px;', $styles);
        self::assertStringContainsString('padding: 17px 18px 16px;', $styles);
        self::assertStringContainsString("@import './category-rebuild';", $app);
    }

    public function test_new_cart_and_category_copy_is_complete_in_primary_locales(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['carts', 'categories'] as $group) {
            $english     = require $root . "/lang/en/shop/{$group}.php";
            $chinese     = require $root . "/lang/zh_cn/shop/{$group}.php";
            $englishKeys = array_keys($english);
            $chineseKeys = array_keys($chinese);
            sort($englishKeys);
            sort($chineseKeys);
            self::assertSame($englishKeys, $chineseKeys, "Translation keys for {$group} must stay aligned.");
        }
    }
}
