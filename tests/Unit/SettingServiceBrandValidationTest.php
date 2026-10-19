<?php

namespace Tests\Unit;

use Beike\Admin\Services\SettingService;
use Exception;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SettingServiceBrandValidationTest extends TestCase
{
    public function test_native_brand_settings_are_normalized_at_the_write_boundary(): void
    {
        $settings = $this->prepare([
            'store_name'          => ' <b>Merchant</b> ',
            'brand_primary_color' => '#155eef',
            'brand_accent_color'  => '#6d28d9',
            'brand_border_radius' => '16',
            'storefront_preset'   => 'editorial',
            'logo'                => 'image/catalog/tenants/demo/logo.webp',
        ]);

        self::assertSame('Merchant', $settings['store_name']);
        self::assertSame('#155EEF', $settings['brand_primary_color']);
        self::assertSame(16, $settings['brand_border_radius']);
        self::assertSame('image/catalog/tenants/demo/logo.webp', $settings['logo']);
    }

    public function test_unsafe_brand_css_and_asset_values_are_rejected(): void
    {
        $this->expectException(Exception::class);
        $this->prepare(['brand_primary_color' => 'red; background:url(javascript:alert(1))']);
    }

    public function test_asset_path_traversal_is_rejected(): void
    {
        $this->expectException(Exception::class);
        $this->prepare(['logo' => '../storage/secrets']);
    }

    private function prepare(array $settings): array
    {
        $method = new ReflectionMethod(SettingService::class, 'prepareBrandSettings');

        return $method->invoke(null, $settings);
    }
}
