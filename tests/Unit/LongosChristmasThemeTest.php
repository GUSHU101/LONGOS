<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LongosChristmasThemeTest extends TestCase
{
    private string $pluginPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginPath = dirname(__DIR__, 2) . '/plugins/LongosChristmasTheme';
    }

    public function test_theme_package_is_independent_and_installable(): void
    {
        $manifest = json_decode((string) file_get_contents($this->pluginPath . '/config.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('longos_christmas', $manifest['code']);
        $this->assertSame('theme', $manifest['type']);
        $this->assertSame('image/theme-preview.svg', $manifest['theme']);
        $this->assertFileExists($this->pluginPath . '/Static/' . $manifest['theme']);
        $this->assertDirectoryExists($this->pluginPath . '/Themes/longos_christmas');
        $this->assertFileExists(dirname(__DIR__, 2) . '/themes/default/layout/master.blade.php');
    }

    public function test_theme_defines_its_own_primary_storefront_surfaces(): void
    {
        foreach ([
            'layout/header.blade.php',
            'layout/footer.blade.php',
            'shared/menu-pc.blade.php',
            'shared/menu-mobile.blade.php',
            'shared/product.blade.php',
            'shared/assurances.blade.php',
            'home.blade.php',
        ] as $relativePath) {
            $this->assertFileExists($this->pluginPath . '/Themes/longos_christmas/' . $relativePath);
        }
    }

    public function test_theme_stylesheet_is_prebuilt_responsive_and_accessibility_aware(): void
    {
        $stylesheet = (string) file_get_contents($this->pluginPath . '/Static/public/build/beike/shop/longos_christmas/css/theme.css');

        $this->assertStringContainsString(':focus-visible', $stylesheet);
        $this->assertStringContainsString('@media (max-width: 767.98px)', $stylesheet);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $stylesheet);
        $this->assertStringContainsString('min-height: 44px', $stylesheet);
        $this->assertStringContainsString('.page-product', $stylesheet);
        $this->assertStringContainsString('.page-cart', $stylesheet);
        $this->assertStringContainsString('.checkout-page', $stylesheet);
        $this->assertStringContainsString('.auth-page', $stylesheet);
        $this->assertStringContainsString('.section-feature-grid', $stylesheet);
        $this->assertStringContainsString('.section-story-block', $stylesheet);
        $this->assertStringContainsString('.section-testimonials', $stylesheet);
        $this->assertStringContainsString('.section-faq', $stylesheet);
    }

    public function test_builder_hero_is_editable_and_does_not_duplicate_the_fallback_hero(): void
    {
        $home = (string) file_get_contents($this->pluginPath . '/Themes/longos_christmas/home.blade.php');

        $this->assertStringContainsString('$hasBuilderHero', $home);
        $this->assertStringContainsString('@unless ($hasBuilderHero)', $home);
        $this->assertStringContainsString("'shopify-slideshow'", $home);
        $this->assertStringContainsString("shop_route('latest_products')", $home);
    }

    public function test_theme_javascript_keeps_navigation_and_footer_keyboard_operable(): void
    {
        $javascript = (string) file_get_contents($this->pluginPath . '/Static/public/build/beike/shop/longos_christmas/js/theme.js');

        $this->assertStringContainsString("event.key === 'ArrowDown'", $javascript);
        $this->assertStringContainsString("event.key !== 'Escape'", $javascript);
        $this->assertStringContainsString("setAttribute('aria-expanded'", $javascript);
        $this->assertStringContainsString('.christmas-footer .footer-toggle', $javascript);
    }

    public function test_theme_translations_have_matching_keys(): void
    {
        $english = require $this->pluginPath . '/Lang/en/theme.php';
        $chinese = require $this->pluginPath . '/Lang/zh_cn/theme.php';

        $this->assertSame(array_keys($english), array_keys($chinese));
        $this->assertNotEmpty($english['hero_title']);
    }
}
