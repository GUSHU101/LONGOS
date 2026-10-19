<?php

namespace Tests\Unit;

use Beike\Services\DesignService;
use PHPUnit\Framework\TestCase;

class ProductDetailPresentationTest extends TestCase
{
    public function test_product_page_removes_requested_metadata_and_favorite_controls(): void
    {
        $root    = dirname(__DIR__, 2);
        $product = (string) file_get_contents($root . '/themes/default/product/product.blade.php');
        $header  = (string) file_get_contents($root . '/themes/default/layout/header.blade.php');

        self::assertStringNotContainsString('product.detail.brand', $product);
        self::assertStringNotContainsString('product.detail.sku', $product);
        self::assertStringNotContainsString('product.detail.model', $product);
        self::assertStringNotContainsString('product-brand-link', $product);
        self::assertStringNotContainsString('product.detail.wishlist', $product);
        self::assertStringNotContainsString("__('shop/products.add_to_favorites')", $product);
        self::assertStringNotContainsString('image/icons/favorite.svg', $header);
        self::assertStringNotContainsString("shop_route('account.wishlist.index')", $header);
        self::assertStringNotContainsString('class="top-wrap"', $header);
    }

    public function test_product_information_assurance_and_images_follow_the_new_contract(): void
    {
        $root        = dirname(__DIR__, 2);
        $product     = (string) file_get_contents($root . '/themes/default/product/product.blade.php');
        $detail      = (string) file_get_contents($root . '/beike/Shop/Http/Resources/ProductDetail.php');
        $sku         = (string) file_get_contents($root . '/beike/Shop/Http/Resources/SkuDetail.php');
        $styles      = (string) file_get_contents($root . '/resources/beike/shop/default/css/product-detail-rebuild.scss');
        $footer      = (string) file_get_contents($root . '/themes/default/layout/footer.blade.php');
        $footerStyle = (string) file_get_contents($root . '/resources/beike/shop/default/css/footer-rebuild.scss');

        $buttonPosition      = strpos($product, 'class="product-btns"');
        $descriptionPosition = strpos($product, 'class="product-description');
        $assurancePosition   = strpos($product, 'class="product-purchase-assurance"');
        self::assertIsInt($buttonPosition);
        self::assertIsInt($descriptionPosition);
        self::assertIsInt($assurancePosition);
        self::assertLessThan($descriptionPosition, $buttonPosition);
        self::assertLessThan($descriptionPosition, $assurancePosition);
        self::assertStringContainsString('class="product-information-shell', $product);

        self::assertStringContainsString("__('shop/products.secure_checkout')", $product);
        self::assertStringContainsString("__('shop/products.tracked_delivery')", $product);
        self::assertStringContainsString("__('shop/products.easy_support')", $product);
        self::assertStringContainsString('role="tablist"', $product);
        self::assertStringContainsString('scope="row"', $product);

        foreach ([$detail, $sku] as $resource) {
            self::assertStringContainsString("'small'", $resource);
            self::assertStringContainsString("'large'", $resource);
            self::assertStringContainsString("'popup'", $resource);
            self::assertStringContainsString('image_origin($image)', $resource);
        }
        self::assertStringContainsString('srcset=', $product);
        self::assertStringContainsString('productImageSrcset', $product);
        self::assertStringContainsString('mix-blend-mode: normal !important', $styles);
        self::assertStringContainsString('class="pdp-gallery-grid', $product);
        self::assertStringContainsString('data-product-gallery-dialog', $product);
        self::assertStringContainsString('renderDesktopProductGallery', $product);
        self::assertStringContainsString("'gallery_image'", $detail);
        self::assertStringContainsString('normalizeProductGalleryImage', $product);
        self::assertStringContainsString('composeProductGalleryImages', $product);
        self::assertStringContainsString('this.selectedVariantImage = normalizeProductGalleryImage', $product);
        self::assertStringContainsString('swiperMobile.slideTo(0, 0, false);', $product);
        self::assertStringContainsString('font-size: clamp(1.5rem, 1.8vw, 2.125rem) !important;', $styles);
        self::assertStringContainsString('body.page-product .product-description .tab-content', $styles);
        self::assertStringContainsString('class="product-assurance-list"', $product);
        self::assertStringContainsString('body.page-product .product-assurance-list > li + li', $styles);
        self::assertStringNotContainsString('product-purchase-assurance__header', $product);
        self::assertStringNotContainsString('product-purchase-assurance__grid', $product);
        self::assertStringNotContainsString('jquery.zoom.min.js', $product);
        self::assertStringNotContainsString("$('#zoom').zoom", $product);
        self::assertStringContainsString('product-cta product-cta--secondary', $product);
        self::assertStringContainsString('product-cta product-cta--primary', $product);
        self::assertStringNotContainsString('class="product-cta__arrow"', $product);
        self::assertStringNotContainsString('class="product-cta__icon"', $product);
        self::assertStringNotContainsString('<span>Sale</span>', $product);
        self::assertStringContainsString("__('shop/products.save_percentage'", $product);
        self::assertStringNotContainsString('bi bi-cart-fill', $product);
        self::assertStringNotContainsString('bi bi-bag-fill', $product);
        self::assertStringContainsString('.product-cta:focus-visible', $styles);
        self::assertStringContainsString('.product-cta:disabled', $styles);
        self::assertStringContainsString('.breadcrumb-item--current', $styles);
        self::assertStringContainsString('.breadcrumb-item:nth-last-child(2)', $styles);
        self::assertStringNotContainsString('body.page-product .breadcrumb-wrap { display: none; }', $styles);
        self::assertStringContainsString('data-product-quantity-stepper', $product);
        self::assertStringContainsString('@click.stop.prevent="decreaseQuantity"', $product);
        self::assertStringContainsString('@click.stop.prevent="increaseQuantity"', $product);
        self::assertStringContainsString('maxPurchasableQuantity()', $product);
        self::assertStringContainsString('handleQuantityInput(event)', $product);
        self::assertStringContainsString('this.normalizeQuantity();', $product);
        self::assertStringContainsString('aria-valuemin="1"', $product);
        self::assertStringContainsString('aria-live="polite"', $product);
        self::assertStringContainsString(':aria-pressed="value.selected', $product);
        self::assertStringContainsString(':disabled="value.disabled"', $product);
        self::assertStringContainsString('.variable-info > button:focus-visible', $styles);
        self::assertStringContainsString('background: var(--pdp-bg) !important;', $styles);
        self::assertStringContainsString('grid-template-columns: 3rem 3.5rem 3rem;', $styles);

        self::assertStringContainsString("__('shop/footer.accepted_payments')", $footer);
        self::assertStringContainsString('class="longos-footer__payments" role="list"', $footer);
        self::assertStringContainsString('footer.longos-footer .longos-footer__payment', $footerStyle);
        self::assertStringNotContainsString('filter: grayscale', $footerStyle);
    }

    public function test_untouched_legacy_black_banner_is_retired_but_custom_banners_remain_available(): void
    {
        $titles = [
            'Spring Fashion Refresh Season: Limited Time 50% Off on New Arrivals',
            'Spring Trend Collection Launch: Special 50% Off Offer',
            'Spring Affection Sale Event: Up to 50% Off on All Items',
            'Spring New Arrivals Mega Sale: Enjoy 50% Off on Fashion',
            'Spring Style Promotion: Select Items Starting at 50% Off',
        ];
        $legacy = DesignService::normalizeHeaderSetting([
            'header_ads' => [
                'active'   => true,
                'bg_color' => '#333333',
                'color'    => '#ffffff',
                'items'    => array_map(fn (string $title): array => ['title' => ['en' => $title]], $titles),
            ],
        ]);
        self::assertFalse($legacy['header_ads']['active']);

        $custom = DesignService::normalizeHeaderSetting([
            'header_ads' => [
                'active'   => true,
                'bg_color' => '#333333',
                'color'    => '#ffffff',
                'items'    => [['title' => ['en' => 'Free shipping this weekend']]],
            ],
        ]);
        self::assertTrue($custom['header_ads']['active']);
        self::assertFalse(DesignService::normalizeHeaderSetting([])['header_ads']['active']);
    }

    public function test_related_products_use_a_conversion_focused_recommendation_contract(): void
    {
        $root       = dirname(__DIR__, 2);
        $product    = (string) file_get_contents($root . '/themes/default/product/product.blade.php');
        $card       = (string) file_get_contents($root . '/themes/default/shared/related-product.blade.php');
        $styles     = (string) file_get_contents($root . '/resources/beike/shop/default/css/product-detail-rebuild.scss');
        $controller = (string) file_get_contents($root . '/beike/Shop/Http/Controllers/ProductController.php');
        $repository = (string) file_get_contents($root . '/beike/Repositories/ProductRepo.php');

        self::assertStringContainsString("@include('shared.related-product'", $product);
        self::assertStringNotContainsString("@include('shared.product', ['product' => \$item])", $product);
        self::assertStringContainsString('class="product related-products-grid"', $product);
        self::assertStringNotContainsString('relations-swiper', $product);
        self::assertStringNotContainsString('related-product-card__action', $card);
        self::assertStringNotContainsString("__('shop/products.add_to_cart')", $card);
        self::assertStringNotContainsString('btn-wishlist', $card);
        self::assertStringNotContainsString('btn-quick-view', $card);
        self::assertStringContainsString('.related-products-grid', $styles);
        self::assertStringNotContainsString('class="relations-eyebrow"', $product);
        self::assertStringNotContainsString('class="relations-subtitle"', $product);
        self::assertStringContainsString('grid-template-columns: repeat(4', $styles);
        self::assertStringContainsString('ProductRepo::getRelatedProducts($product, $relationIds, 4)', $controller);
        self::assertStringContainsString('ProductRepo::getRelatedProducts', $controller);
        self::assertStringContainsString('Curated relations keep their order', $repository);
        self::assertStringContainsString("'sort'        => 'sales'", $repository);
        self::assertStringContainsString("where('quantity', '>', 0)", $repository);
    }

    public function test_product_translations_stay_complete_for_each_supported_storefront_locale(): void
    {
        $root    = dirname(__DIR__, 2);
        $english = require $root . '/lang/en/shop/products.php';
        $chinese = require $root . '/lang/zh_cn/shop/products.php';

        $englishKeys = array_keys($english);
        $chineseKeys = array_keys($chinese);
        sort($englishKeys);
        sort($chineseKeys);
        self::assertSame($englishKeys, $chineseKeys);
        self::assertSame('Protected payment processing', $english['secure_checkout_description']);
        self::assertSame('发货后可随时追踪订单', $chinese['tracked_delivery_description']);
        self::assertSame('You may also like', $english['related_products_title']);
        self::assertSame('你可能也喜欢', $chinese['related_products_title']);
    }
}
