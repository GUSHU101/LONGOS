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
        $primary    = (string) file_get_contents($root . '/themes/default/design/img_text_slideshow.blade.php');
        $second     = (string) file_get_contents($root . '/themes/default/design/img_text_slideshow_2.blade.php');

        self::assertStringContainsString('@media (max-width: 767.98px)', $primaryCss);
        self::assertStringContainsString('object-fit: contain;', $primaryCss);
        self::assertStringContainsString('aspect-ratio: auto;', $secondCss);
        self::assertStringContainsString('object-fit: contain;', $secondCss);
        self::assertStringContainsString('.shopify-slideshow__image { height: auto; min-height: 0; object-fit: contain; }', $shopifyCss);
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
