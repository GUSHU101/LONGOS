<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class StorefrontResourceLoadingTest extends TestCase
{
    public function test_critical_storefront_styles_are_discovered_before_blocking_jquery_and_application_scripts_are_deferred(): void
    {
        $root   = dirname(__DIR__, 2);
        $master = (string) file_get_contents($root . '/themes/default/layout/master.blade.php');

        $appStyle = strpos($master, "css/app.css') }}");
        $jquery   = strpos($master, "jquery/jquery-3.6.0.min.js') }}");

        self::assertNotFalse($appStyle);
        self::assertNotFalse($jquery);
        self::assertLessThan($jquery, $appStyle);
        self::assertStringContainsString("layer/3.5.1/layer.js') }}\" defer", $master);
        self::assertStringContainsString("bootstrap/js/bootstrap.bundle.min.js') }}\" defer", $master);
        self::assertStringContainsString("js/app.js') }}\" defer", $master);
        self::assertStringContainsString("jquery/jquery-3.6.0.min.js') }}\"></script>", $master);
    }

    public function test_design_carousels_defer_swiper_without_running_before_it_is_ready(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['slideshow', 'img_text_slideshow', 'img_text_slideshow_2', 'page', 'product'] as $view) {
            $source = (string) file_get_contents($root . "/themes/default/design/{$view}.blade.php");

            self::assertStringContainsString(
                "@addScript(asset('vendor/swiper/swiper-bundle.min.js'), ['defer' => true])",
                $source,
            );
        }

        $slideshow = (string) file_get_contents($root . '/themes/default/design/slideshow.blade.php');
        self::assertStringContainsString('$(function () {', $slideshow);
        self::assertGreaterThan(strpos($slideshow, '$(function () {'), strpos($slideshow, 'slideshowSwiper();'));
    }
}
