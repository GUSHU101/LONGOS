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

    public function test_product_cards_offer_real_responsive_image_candidates(): void
    {
        $root     = dirname(__DIR__, 2);
        $resource = (string) file_get_contents($root . '/beike/Shop/Http/Resources/ProductSimple.php');
        $card     = (string) file_get_contents($root . '/themes/default/shared/product.blade.php');
        $styles   = (string) file_get_contents($root . '/resources/beike/shop/default/css/longos-commerce.scss');

        self::assertStringContainsString("'image_srcsets'", $resource);
        self::assertStringContainsString('foreach ([320, 640, 960, 1280] as $width)', $resource);
        self::assertStringContainsString('getimagesize($absolutePath)', $resource);
        self::assertStringContainsString('data-srcset=', $card);
        self::assertStringContainsString('data-sizes="auto"', $card);
        self::assertStringContainsString('srcset=', $card);
        self::assertStringContainsString('mix-blend-mode: normal;', $styles);
        self::assertStringContainsString('@media (hover: none)', $styles);
    }

    public function test_webp_uploads_use_versioned_responsive_derivatives(): void
    {
        $root         = dirname(__DIR__, 2);
        $helpers      = (string) file_get_contents($root . '/beike/Helpers.php');
        $imageService = (string) file_get_contents($root . '/beike/Services/ImageService.php');
        $detail       = (string) file_get_contents($root . '/beike/Shop/Http/Resources/ProductDetail.php');
        $sku          = (string) file_get_contents($root . '/beike/Shop/Http/Resources/SkuDetail.php');

        self::assertStringNotContainsString("str_contains(\$image, '.webp')", $helpers);
        self::assertStringContainsString("str_contains(strtolower(\$image), '.gif')", $helpers);
        self::assertStringContainsString('$sourceVersion = sprintf(', $imageService);
        self::assertStringContainsString(". '-v' . \$sourceVersion", $imageService);
        foreach ([$detail, $sku] as $resource) {
            self::assertStringContainsString('image_resize($image, 800, calculate_height_by_ratio(800))', $resource);
            self::assertStringContainsString('image_resize($image, 1200, calculate_height_by_ratio(1200))', $resource);
            self::assertStringNotContainsString('image_resize($image, 1600,', $resource);
        }
    }
}
