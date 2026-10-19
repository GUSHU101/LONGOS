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

        self::assertStringContainsString('grid-template-columns: minmax(0, 1fr) minmax(320px, 390px);', $styles);
        self::assertStringContainsString('min-height: 44px;', $styles);
        self::assertStringContainsString('@media (max-width: 575.98px)', $styles);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        self::assertStringContainsString("@import './cart-rebuild';", $app);
    }

    public function test_category_uses_semantic_filters_and_a_fluid_product_grid(): void
    {
        $root    = dirname(__DIR__, 2);
        $page    = (string) file_get_contents($root . '/themes/default/category.blade.php');
        $bar     = (string) file_get_contents($root . '/themes/default/shared/filter_bar_block.blade.php');
        $sidebar = (string) file_get_contents($root . '/themes/default/shared/filter_sidebar_block.blade.php');
        $styles  = (string) file_get_contents($root . '/resources/beike/shop/default/css/category-rebuild.scss');
        $app     = (string) file_get_contents($root . '/resources/beike/shop/default/css/app.scss');

        self::assertStringContainsString('class="category-filter-toggle"', $page);
        self::assertStringContainsString('aria-controls="category-filter-panel"', $page);
        self::assertStringContainsString('class="category-layout"', $page);
        self::assertStringContainsString('class="category-product-grid', $page);
        self::assertStringContainsString('class="filter-value-chip"', $page);
        self::assertStringContainsString("e.key === 'Escape'", $page);
        self::assertStringNotContainsString('class="mb-filter"', $page);

        self::assertStringContainsString('<fieldset class="style-wrap"', $bar);
        self::assertStringContainsString('class="product-tool-select product-tool-select--sort"', $bar);
        self::assertStringContainsString('for="category-filter-', $sidebar);
        self::assertStringContainsString('name="filter_attr[]"', $sidebar);

        self::assertStringContainsString('grid-template-columns: repeat(4, minmax(0, 1fr));', $styles);
        self::assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr));', $styles);
        self::assertStringContainsString('width: min(90vw, 410px) !important;', $styles);
        self::assertStringContainsString('overflow-x: clip;', $styles);
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
