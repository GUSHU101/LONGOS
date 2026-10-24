<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class GlobalAccessibilityPresentationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_templates_do_not_disable_zoom_or_force_positive_tab_order(): void
    {
        foreach (['resources', 'themes', 'plugins'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root . '/' . $directory));

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());
                self::assertDoesNotMatchRegularExpression('/maximum-scale\s*=|user-scalable\s*=\s*no/i', $contents, $file->getPathname());
                self::assertDoesNotMatchRegularExpression('/tabindex\s*=\s*["\'][1-9][0-9]*["\']/i', $contents, $file->getPathname());
            }
        }
    }

    public function test_shared_admin_actions_use_native_interactive_controls(): void
    {
        $pageTitle = (string) file_get_contents($this->root . '/resources/beike/admin/views/components/page-title-box.blade.php');
        $image     = (string) file_get_contents($this->root . '/resources/beike/admin/views/shared/vue-image.blade.php');
        $selector  = (string) file_get_contents($this->root . '/resources/beike/admin/views/pages/app_push/component/link_selector.blade.php');

        self::assertStringContainsString('<button type="button" class="btn btn-default btn-sm me-2" onclick="history.back()"', $pageTitle);
        self::assertStringContainsString('<button type="button" class="set-product-img', $image);
        self::assertStringContainsString('aria-label="{{ __(\'admin/builder.modules_select_image\') }}"', $image);
        self::assertStringContainsString('aria-haspopup="listbox"', $selector);
        self::assertStringContainsString('class="selector-option" role="option"', $selector);
    }

    public function test_product_gallery_is_keyboard_operable_without_javascript_links(): void
    {
        $product = (string) file_get_contents($this->root . '/themes/default/product/product.blade.php');

        self::assertMatchesRegularExpression('/<button\s+type="button"\s+class="pdp-gallery-item /', $product);
        self::assertStringContainsString('data-product-gallery-open', $product);
        self::assertStringContainsString('aria-label="{{ $galleryButtonLabel }}"', $product);
        self::assertStringContainsString("\$galleryButtonLabel .= ' · ' . __('shop/products.view_all_images'", $product);
        self::assertStringContainsString('<dialog class="pdp-media-dialog"', $product);
        self::assertStringContainsString('type="button" class="pdp-media-dialog__close"', $product);
        self::assertStringNotContainsString('class="product-gallery-thumb"', $product);
        self::assertStringNotContainsString('href="javascript:;"', $product);
    }

    public function test_breadcrumb_marks_the_current_location_without_linking_to_it(): void
    {
        $breadcrumb = (string) file_get_contents($this->root . '/themes/default/components/breadcrumbs.blade.php');

        self::assertStringContainsString('class="breadcrumb-nav" aria-label="breadcrumb"', $breadcrumb);
        self::assertStringContainsString('&& ! $loop->last', $breadcrumb);
        self::assertStringContainsString('class="breadcrumb-item breadcrumb-item--current active" aria-current="page"', $breadcrumb);
        self::assertStringContainsString('class="breadcrumb-current"', $breadcrumb);
    }
}
