<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class StorefrontMediaPresentationTest extends TestCase
{
    public function test_mobile_slideshows_preserve_the_complete_image(): void
    {
        $root       = dirname(__DIR__, 2);
        $primaryCss = (string) file_get_contents($root . '/resources/beike/shop/default/css/module/module-img-text-slideshow.scss');
        $secondCss  = (string) file_get_contents($root . '/resources/beike/shop/default/css/module/module-img-text-slideshow-2.scss');
        $shopifyCss = (string) file_get_contents($root . '/resources/beike/shop/default/css/module/module-shopify-sections.scss');
        $storefront = (string) file_get_contents($root . '/resources/beike/shop/default/js/app.js');
        $mediaJs    = (string) file_get_contents($root . '/resources/beike/shop/default/js/slideshow-media.js');
        $primary    = (string) file_get_contents($root . '/themes/default/design/img_text_slideshow.blade.php');
        $second     = (string) file_get_contents($root . '/themes/default/design/img_text_slideshow_2.blade.php');

        self::assertStringContainsString('@media (max-width: 767.98px)', $primaryCss);
        self::assertStringContainsString('aspect-ratio: 4 / 3;', $primaryCss);
        self::assertStringContainsString('object-fit: contain;', $primaryCss);
        self::assertStringContainsString('aspect-ratio: 4 / 3;', $secondCss);
        self::assertStringContainsString('aspect-ratio: 4 / 5;', $secondCss);
        self::assertStringContainsString('data-mobile-media-shape="landscape"', $secondCss);
        self::assertStringContainsString('data-mobile-media-shape="square"', $secondCss);
        self::assertStringContainsString('object-fit: contain;', $secondCss);
        self::assertStringNotContainsString('.has-mobile-image .slide-media', $primaryCss);
        self::assertStringNotContainsString('.has-mobile-image .slide-media', $secondCss);
        self::assertStringContainsString('.slide-backdrop-picture', $secondCss);
        self::assertStringContainsString('.slide-media-backdrop', $secondCss);
        self::assertStringContainsString('data-builder-module-code="img_text_slideshow_2"', $secondCss);
        self::assertStringContainsString('.shopify-slideshow__image { height: auto; min-height: 0; object-fit: contain; }', $shopifyCss);
        self::assertStringContainsString('<picture class="slide-picture">', $primary);
        self::assertStringContainsString('<picture class="slide-backdrop-picture" aria-hidden="true">', $primary);
        self::assertStringContainsString('media="(max-width: 767.98px)"', $primary);
        self::assertStringContainsString('<picture class="slide-picture">', $second);
        self::assertStringContainsString('<picture class="slide-backdrop-picture" aria-hidden="true">', $second);
        self::assertStringContainsString('$image[\'mobile_image\']', $second);
        self::assertStringContainsString('data-longos-slideshow-media', $second);
        self::assertStringContainsString('slideshow?.updateAutoHeight?.(0)', $primary);
        self::assertStringContainsString('slideshow?.updateAutoHeight?.(0)', $second);
        self::assertStringContainsString("import './slideshow-media'", $storefront);
        self::assertStringContainsString("return 'landscape'", $mediaJs);
        self::assertStringContainsString("return 'portrait'", $mediaJs);
        self::assertStringContainsString('autoHeight: true', $primary);
        self::assertStringContainsString('autoHeight: true', $second);
    }

    public function test_product_cards_never_generate_derivatives_during_storefront_requests(): void
    {
        $root          = dirname(__DIR__, 2);
        $resource      = (string) file_get_contents($root . '/beike/Shop/Http/Resources/ProductSimple.php');
        $card          = (string) file_get_contents($root . '/themes/default/shared/product.blade.php');
        $christmasCard = (string) file_get_contents($root . '/plugins/LongosChristmasTheme/Themes/longos_christmas/shared/product.blade.php');
        $styles        = (string) file_get_contents($root . '/resources/beike/shop/default/css/longos-commerce.scss');

        self::assertStringContainsString("'image_srcsets'", $resource);
        self::assertStringContainsString('getimagesize($absolutePath)', $resource);
        self::assertStringContainsString("'url'    => image_origin(\$image)", $resource);
        self::assertStringNotContainsString('image_resize(', $resource);
        self::assertStringContainsString('data-srcset=', $card);
        self::assertStringContainsString('data-sizes="auto"', $card);
        self::assertStringContainsString('srcset=', $card);
        self::assertStringContainsString("\$placeholder = image_origin('');", $card);
        self::assertStringNotContainsString('image_resize(', $card);
        self::assertStringNotContainsString('image_resize(', $christmasCard);
        self::assertStringContainsString('mix-blend-mode: normal;', $styles);
        self::assertStringContainsString('@media (hover: none)', $styles);
    }

    public function test_webp_derivatives_are_versioned_and_product_pages_are_failure_safe(): void
    {
        $root         = dirname(__DIR__, 2);
        $helpers      = (string) file_get_contents($root . '/beike/Helpers.php');
        $imageService = (string) file_get_contents($root . '/beike/Services/ImageService.php');
        $job          = (string) file_get_contents($root . '/app/Jobs/GenerateImageDerivative.php');
        $dockerfile   = (string) file_get_contents($root . '/docker/nginx/Dockerfile');
        $detail       = (string) file_get_contents($root . '/beike/Shop/Http/Resources/ProductDetail.php');
        $sku          = (string) file_get_contents($root . '/beike/Shop/Http/Resources/SkuDetail.php');

        self::assertStringNotContainsString("str_contains(\$image, '.webp')", $helpers);
        self::assertStringContainsString("str_contains(strtolower(\$image), '.gif')", $helpers);
        self::assertStringContainsString('$sourceVersion = sprintf(', $imageService);
        self::assertStringContainsString("self::DERIVATIVE_VERSION . '-v' . \$sourceVersion", $imageService);
        self::assertStringContainsString('flock($lock, LOCK_EX)', $imageService);
        self::assertStringContainsString("['jpg', 'jpeg', 'webp'], true) ? 82 : null", $imageService);
        self::assertStringContainsString('decodeFirstFrameWithImagick', $imageService);
        self::assertStringContainsString('$source->coalesceImages()', $imageService);
        self::assertStringContainsString('pecl install imagick-3.7.0', $dockerfile);
        self::assertStringContainsString('"imagick"', $dockerfile);
        self::assertStringContainsString('catch (Throwable)', $imageService);
        self::assertStringNotContainsString("ini_set('memory_limit', '-1')", $imageService);
        self::assertStringContainsString('function image_cached_resize(', $helpers);
        self::assertStringContainsString('public function cachedResize(', $imageService);
        self::assertStringContainsString("config('queue.default', 'sync') !== 'sync'", $imageService);
        self::assertStringContainsString('implements ShouldBeUnique, ShouldQueue', $job);
        self::assertStringContainsString('resize($this->width, $this->height, true)', $job);
        self::assertStringContainsString('MAX_SOURCE_PIXELS = 40_000_000', $imageService);
        self::assertStringContainsString('assertSourceDimensionsAreSafe()', $imageService);
        self::assertStringContainsString('intdiv(self::MAX_SOURCE_PIXELS, $height)', $imageService);
        self::assertStringContainsString('bool $throwOnFailure = false', $imageService);
        self::assertStringContainsString("'preview' => image_cached_resize(\$image, 800", $detail);
        self::assertStringContainsString("'popup'   => \$origin", $detail);
        self::assertStringNotContainsString('image_resize(', $detail);
        self::assertStringContainsString("'preview' => image_cached_resize(\$image, 800", $sku);
        self::assertStringContainsString('calculate_height_by_ratio(800), false', $sku);
        self::assertStringNotContainsString('image_resize(', $sku);
    }

    public function test_storefront_media_uses_long_lived_cache_headers(): void
    {
        $nginx = (string) file_get_contents(dirname(__DIR__, 2) . '/docker/nginx/nginx-site.conf');

        self::assertStringContainsString('location ~* ^/(?:upload|image/catalog)/', $nginx);
        self::assertStringContainsString('max-age=31536000, immutable', $nginx);
        self::assertStringContainsString('location ~* ^/plugin/', $nginx);
        self::assertStringContainsString('max-age=2592000', $nginx);
        self::assertStringContainsString('location ^~ /vendor/', $nginx);
    }

    public function test_storefront_fonts_prefer_compressed_woff2_assets(): void
    {
        $root       = dirname(__DIR__, 2);
        $styles     = (string) file_get_contents($root . '/resources/beike/shop/default/css/global.scss');
        $icons      = (string) file_get_contents($root . '/resources/beike/shop/default/css/bootstrap/bootstrap-icons.scss');
        $iconSubset = $root . '/public/vendor/bootstrap/icon/bootstrap-icons-storefront.woff2';
        $iconFull   = $root . '/public/vendor/bootstrap/icon/bootstrap-icons.woff2';

        foreach (['Regular', 'Medium', 'SemiBold'] as $weight) {
            $woff2 = $root . "/public/fonts/jost/Jost-{$weight}.woff2";

            self::assertFileExists($woff2);
            self::assertGreaterThan(1000, filesize($woff2));
            self::assertStringContainsString("Jost-{$weight}.woff2') format('woff2')", $styles);
            self::assertLessThan(filesize($root . "/public/fonts/jost/Jost-{$weight}.ttf"), filesize($woff2));
        }

        self::assertFileExists($iconSubset);
        self::assertGreaterThan(1000, filesize($iconSubset));
        self::assertLessThan(filesize($iconFull) / 4, filesize($iconSubset));
        self::assertStringContainsString('bootstrap-icons-storefront.woff2', $icons);
        self::assertStringContainsString('$bootstrap-icons-font, $bootstrap-icons-font-full', $icons);
    }

    public function test_product_media_does_not_block_or_download_hidden_desktop_gallery_assets(): void
    {
        $root    = dirname(__DIR__, 2);
        $master  = (string) file_get_contents($root . '/themes/default/layout/master.blade.php');
        $product = (string) file_get_contents($root . '/themes/default/product/product.blade.php');

        self::assertStringContainsString("jquery/jquery-3.6.0.min.js') }}\" @if(\$deferProductScripts) defer", $master);
        self::assertStringNotContainsString("@addStyle(asset('vendor/swiper/swiper-bundle.min.css'))", $product);
        self::assertStringContainsString('media="(min-width: 992px)"', $product);
        self::assertStringContainsString("const desktopGalleryMedia = window.matchMedia('(min-width: 992px)');", $product);
        self::assertStringContainsString('if (!desktopGalleryMedia.matches || !gallery', $product);
        self::assertStringContainsString('const candidates = preloadAdjacent ?', $product);
    }
}
