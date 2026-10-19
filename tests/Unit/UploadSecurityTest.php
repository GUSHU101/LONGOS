<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class UploadSecurityTest extends TestCase
{
    public function test_storefront_uploads_require_a_customer_and_have_a_size_limit(): void
    {
        $root         = dirname(__DIR__, 2);
        $routes       = (string) file_get_contents($root . '/beike/Shop/Routes/shop.php');
        $request      = (string) file_get_contents($root . '/beike/Shop/Http/Requests/UploadRequest.php');
        $adminRequest = (string) file_get_contents($root . '/beike/Admin/Http/Requests/UploadRequest.php');
        $adminUpload  = (string) file_get_contents($root . '/beike/Admin/Http/Controllers/FileController.php');
        $provider     = (string) file_get_contents($root . '/beike/Shop/Providers/ShopServiceProvider.php');
        $settings     = (string) file_get_contents($root . '/resources/beike/admin/views/pages/setting/partials/brand.blade.php');

        self::assertMatchesRegularExpression(
            "/Route::post\('files'.*?->middleware\('shop_auth:' \. Customer::AUTH_GUARD\)/s",
            $routes,
        );
        self::assertStringContainsString('mimes:jpg,png,jpeg,gif,webp,mp4|max:51200', $request);
        self::assertStringContainsString('mimes:jpg,png,jpeg,gif,webp,mp4|max:51200', $adminRequest);
        self::assertStringContainsString("'file' => 'required|file|max:51200'", $adminUpload);
        self::assertStringContainsString('if (! $file->isValid())', $adminUpload);
        self::assertStringContainsString('@getimagesize($file->getRealPath())', $adminUpload);
        self::assertStringContainsString("if (! is_string(\$path) || \$path === '')", $adminUpload);
        self::assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $adminUpload);
        self::assertStringContainsString('application/vnd.openxmlformats-officedocument.presentationml.presentation', $adminUpload);
        self::assertStringContainsString("'public'  => 0644", $provider);
        self::assertStringContainsString("admin_route('file.store')", $settings);
        self::assertStringNotContainsString("shop_route('file.store')", $settings);
    }
}
