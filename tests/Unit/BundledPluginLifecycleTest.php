<?php

namespace Tests\Unit;

use App\Services\BundledPluginSynchronizer;
use PHPUnit\Framework\TestCase;

class BundledPluginLifecycleTest extends TestCase
{
    public function test_release_owned_runtime_plugins_cannot_be_uninstalled(): void
    {
        foreach (BundledPluginSynchronizer::packageCodes() as $code) {
            self::assertTrue(BundledPluginSynchronizer::isBundled($code));
        }
        self::assertFalse(BundledPluginSynchronizer::isBundled('merchant_extension'));

        $repository = (string) file_get_contents(dirname(__DIR__, 2) . '/beike/Repositories/PluginRepo.php');
        self::assertStringContainsString('BundledPluginSynchronizer::isBundled', $repository);
        self::assertStringContainsString('数据库回滚失败', $repository);
        self::assertStringContainsString('throw new RuntimeException', $repository);
    }

    public function test_payment_plugins_default_to_disabled_until_credentials_are_valid(): void
    {
        $synchronizer = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/BundledPluginSynchronizer.php');
        $seeder       = (string) file_get_contents(dirname(__DIR__, 2) . '/database/seeders/SettingsSeeder.php');
        $checkout     = (string) file_get_contents(dirname(__DIR__, 2) . '/beike/Shop/Services/CheckoutService.php');
        $repository   = (string) file_get_contents(dirname(__DIR__, 2) . '/beike/Repositories/PluginRepo.php');
        $controller   = (string) file_get_contents(dirname(__DIR__, 2) . '/beike/Admin/Http/Controllers/PluginController.php');

        self::assertMatchesRegularExpression("/'paypal'[\\s\\S]{0,500}'status' => '0'/", $synchronizer);
        self::assertMatchesRegularExpression("/'stripe'[\\s\\S]{0,250}'status' => '0'/", $synchronizer);
        self::assertStringContainsString("'space' => 'paypal', 'name' => 'status', 'value' => '0'", $seeder);
        self::assertStringContainsString("'space' => 'stripe', 'name' => 'status', 'value' => '0'", $seeder);
        self::assertStringContainsString('PaymentPluginReadiness::issues', $checkout);
        self::assertStringContainsString('PaymentPluginReadiness::isReady', $repository);
        self::assertStringContainsString('请先在插件设置中完成并保存支付凭据', $controller);
    }
}
