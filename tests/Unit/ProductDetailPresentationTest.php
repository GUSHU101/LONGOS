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
        $root         = dirname(__DIR__, 2);
        $product      = (string) file_get_contents($root . '/themes/default/product/product.blade.php');
        $detail       = (string) file_get_contents($root . '/beike/Shop/Http/Resources/ProductDetail.php');
        $sku          = (string) file_get_contents($root . '/beike/Shop/Http/Resources/SkuDetail.php');
        $styles       = (string) file_get_contents($root . '/resources/beike/shop/default/css/product-detail-rebuild.scss');
        $commerce     = (string) file_get_contents($root . '/resources/beike/shop/default/css/longos-commerce.scss');
        $master       = (string) file_get_contents($root . '/themes/default/layout/master.blade.php');
        $footer       = (string) file_get_contents($root . '/themes/default/layout/footer.blade.php');
        $paymentTrust = (string) file_get_contents($root . '/themes/default/design/payment_trust.blade.php');
        $footerStyle  = (string) file_get_contents($root . '/resources/beike/shop/default/css/footer-rebuild.scss');

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
        self::assertStringContainsString("\$footer_content['bottom']['enabled_payments']", $product);
        self::assertStringNotContainsString('FooterRepo::availablePaymentMarks()', $product);
        self::assertStringContainsString("\$footer_content['bottom']['enabled_payments']", $paymentTrust);
        self::assertStringContainsString('$visiblePaymentMarks', $paymentTrust);
        self::assertStringNotContainsString('<li class="is-paypal" aria-label="PayPal">PayPal</li>', $product);
        self::assertStringContainsString('<details class="product-information-disclosure product-information-disclosure--description" name="product-information">', $product);
        self::assertStringContainsString('product-information-disclosure--attributes" name="product-information"', $product);
        self::assertStringContainsString('<summary>', $product);
        self::assertStringContainsString('product-information-disclosure__panel', $product);
        self::assertStringContainsString('product-information-disclosure__icon" aria-hidden="true"', $product);
        self::assertStringNotContainsString('role="tablist"', $product);
        self::assertStringContainsString('scope="row"', $product);

        foreach ([$detail, $sku] as $resource) {
            self::assertStringContainsString("'small'", $resource);
            self::assertStringContainsString("'large'", $resource);
            self::assertStringContainsString("'popup'", $resource);
            self::assertStringContainsString('image_origin($image)', $resource);
        }
        self::assertStringContainsString('srcset=', $product);
        self::assertStringContainsString('productImageSrcset', $product);
        self::assertStringContainsString("array_slice(\$product['images'] ?? [], 0, \$desktopGalleryLimit)", $product);
        self::assertStringContainsString('desktopProductGalleryLimit = 5', $product);
        self::assertStringContainsString('data-product-gallery-src=', $product);
        self::assertStringContainsString('hydrateMobileProductGalleryAround', $product);
        self::assertStringContainsString('descriptionImagesLazy();', $product);
        self::assertGreaterThan(
            strpos($product, 'const descriptionImagesLazy = () =>'),
            strpos($product, 'descriptionImagesLazy();'),
            'Description lazy-loading must run only after its function is initialized.',
        );
        self::assertStringContainsString('data-product-gallery-previous', $product);
        self::assertStringContainsString('data-product-gallery-next', $product);
        self::assertStringContainsString("@section('preloads')", $product);
        self::assertStringContainsString('fetchpriority="high"', $product);
        self::assertStringContainsString("\$deferProductScripts = trim(\$__env->yieldContent('body-class')) === 'page-product';", $master);
        self::assertStringContainsString("js/app.js') }}\" @if(\$deferProductScripts) defer @endif", $master);
        self::assertStringNotContainsString("@section('bk-page-loading', true)", $product);
        self::assertStringNotContainsString('900w', $product);
        self::assertStringNotContainsString('1600w', $product);
        self::assertStringContainsString('mix-blend-mode: normal !important', $styles);
        self::assertStringContainsString('class="pdp-gallery-grid', $product);
        self::assertStringContainsString('data-product-gallery-dialog', $product);
        self::assertStringContainsString('renderDesktopProductGallery', $product);
        self::assertStringContainsString("'gallery_image'", $detail);
        self::assertStringContainsString('normalizeProductGalleryImage', $product);
        self::assertStringContainsString('composeProductGalleryImages', $product);
        self::assertStringContainsString('this.selectedVariantImage = normalizeProductGalleryImage', $product);
        self::assertStringContainsString('if (reload) this.syncProductImages(sku);', $product);
        self::assertStringNotContainsString("\n          this.syncProductImages(sku);\n          this.product = sku;", $product);
        self::assertStringContainsString('swiperMobile.slideTo(0, 0, false);', $product);
        self::assertStringContainsString('.pdp-gallery-item__count', $styles);
        self::assertStringContainsString('.pdp-media-dialog__nav', $styles);
        self::assertStringContainsString('font-size: clamp(1.5rem, 1.8vw, 2.125rem) !important;', $styles);
        self::assertStringContainsString('body.page-product .product-information-disclosure__panel', $styles);
        self::assertStringContainsString('summary:focus-visible', $styles);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
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
        self::assertStringContainsString('class="form-select product-variant-select"', $product);
        self::assertStringContainsString('@change="selectVariableValue(variable_index, $event)"', $product);
        self::assertStringContainsString('selectVariableValue(variable_index, event)', $product);
        self::assertStringContainsString(':disabled="value.disabled"', $product);
        self::assertStringContainsString('.product-variant-select:focus', $styles);
        self::assertStringContainsString('background-color: #fff !important;', $commerce);
        self::assertStringNotContainsString(".form-control, .form-select, .el-input__inner, .el-textarea__inner {\n  background: #fff !important;", $commerce);
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

    public function test_product_icon_modules_use_compact_mobile_rhythm_without_cramping_copy(): void
    {
        $root          = dirname(__DIR__, 2);
        $icons         = (string) file_get_contents($root . '/themes/default/design/icons.blade.php');
        $iconStyles    = (string) file_get_contents($root . '/resources/beike/shop/default/css/module/module-icons.scss');
        $productStyles = (string) file_get_contents($root . '/resources/beike/shop/default/css/product-detail-rebuild.scss');

        self::assertStringContainsString('$imageOnly = $hasIcons && $visibleIconItems->every', $icons);
        self::assertStringContainsString("{{ \$imageOnly ? 'module-icons--image-only' : '' }}", $icons);
        self::assertStringContainsString('builder-section-grid--mobile-auto', $icons);
        self::assertStringContainsString("'builder-section-grid--mobile-' . max(1, min(4", $icons);
        self::assertStringContainsString('.product-marketing-modules > .builder-responsive-module', $productStyles);
        self::assertStringContainsString('margin-block: 0;', $productStyles);
        self::assertStringContainsString('.module-icons--image-only', $productStyles);
        self::assertStringContainsString('grid-template-columns: repeat(auto-fit, minmax(4.75rem, 1fr));', $iconStyles);
        self::assertStringContainsString('grid-template-columns: repeat(4, minmax(0, 1fr));', $iconStyles);
        self::assertStringContainsString('width: clamp(3rem, 15vw, 3.5rem);', $productStyles);
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
        self::assertSame('Free worldwide shipping', $english['tracked_delivery']);
        self::assertSame('Free delivery on every order worldwide', $english['tracked_delivery_description']);
        self::assertSame('全球免费配送', $chinese['tracked_delivery']);
        self::assertSame('所有订单均享全球免运费', $chinese['tracked_delivery_description']);
        self::assertSame('You may also like', $english['related_products_title']);
        self::assertSame('你可能也喜欢', $chinese['related_products_title']);
    }
}
