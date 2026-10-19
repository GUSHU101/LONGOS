<?php

namespace Tests\Unit;

use Beike\Admin\Services\SettingsReturnUrl;
use PHPUnit\Framework\TestCase;

class SettingsReturnUrlTest extends TestCase
{
    public function test_it_accepts_only_same_origin_settings_urls(): void
    {
        $fallback = 'https://shop.example/admin/settings';

        self::assertSame(
            'https://shop.example/admin/settings/policies?tab=content',
            SettingsReturnUrl::resolve(
                'https://shop.example/admin/settings/policies?tab=content',
                'https://shop.example',
                'admin',
                $fallback
            )
        );
        self::assertSame($fallback, SettingsReturnUrl::resolve('https://attacker.example/admin/settings', 'https://shop.example', 'admin', $fallback));
        self::assertSame($fallback, SettingsReturnUrl::resolve('https://shop.example/admin/products', 'https://shop.example', 'admin', $fallback));
        self::assertSame($fallback, SettingsReturnUrl::resolve('//attacker.example/admin/settings', 'https://shop.example', 'admin', $fallback));
        self::assertSame($fallback, SettingsReturnUrl::resolve('https://shop.example/admin/settings/%2e%2e/products', 'https://shop.example', 'admin', $fallback));
    }

    public function test_it_replaces_only_the_admin_path_prefix(): void
    {
        self::assertSame(
            'https://shop.example/control/settings/basic?source=admin#form',
            SettingsReturnUrl::replaceAdminPrefix(
                'https://shop.example/admin/settings/basic?source=admin#form',
                'admin',
                'control'
            )
        );
    }
}
