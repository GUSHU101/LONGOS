<?php

namespace Plugin\BeikeShopTestSandbox;

/**
 * Registers the TEST-only payment-security sandbox with BeikeShop.
 *
 * This plugin deliberately exposes no real payment flow.  Its storefront
 * component only accepts the exact synthetic PAN profiles enforced again by
 * the controller.
 */
class Bootstrap
{
    public function boot(): void
    {
        $configPath = __DIR__ . '/Config/beikeshop_test.php';
        if (is_file($configPath)) {
            config()->set('beikeshop_test', require $configPath);
        }

        // The provider only boots enabled plugins. Returning an empty payment
        // collection therefore disables normal payment methods at runtime
        // without mutating their saved status; they recover on plugin disable.
        add_hook_filter('repo.plugin.payment_methods', static function ($methods) {
            if (! config('beikeshop_test.checkout_takeover.enabled', false)) {
                return $methods;
            }

            return $methods instanceof \Illuminate\Support\Collection
                ? $methods->filter(static fn (): bool => false)->values()
                : $methods;
        });

        add_hook_filter('admin.components.sidebar.menus', function (array $menus): array {
            $menus[] = [
                'route'    => 'beikeshop_test_sandbox.radar',
                'title'    => 'TEST 支付安全雷达',
                'icon'     => 'bi bi-shield-check',
                'prefixes' => ['beikeshop_test_sandbox'],
                'children' => [],
            ];

            return $menus;
        });

        add_hook_filter('role.permissions.plugin', function (array $permissions): array {
            $permissions[] = [
                'title'       => 'TEST 支付安全沙盒',
                'permissions' => [[
                    'code' => 'manage-test-checkout',
                    'name' => '管理 TEST 支付安全雷达与合成数据',
                ]],
            ];

            return $permissions;
        });

        add_hook_filter('admin.design.index.data', function (array $data): array {
            $data['editor_catalog'][] = [
                'code'              => 'test_checkout_sandbox',
                'name'              => 'TEST 支付安全沙盒',
                'description'       => '仅使用内置合成测试卡和本地 TEST OTP 的防御性支付安全演示。',
                'view_path'         => 'BeikeShopTestSandbox::shop.test_checkout',
                'category'          => 'extension',
                'component_version' => 1,
                'available_on'      => ['home', 'product', 'category', 'page'],
                'max_instances'     => 1,
                'keywords'          => ['test', 'sandbox', 'checkout', 'security'],
                'make'              => [
                    'title'     => 'LONGOS Payment Security Sandbox',
                    'placement' => 'home',
                ],
                'fields' => [[
                    'id'      => 'title',
                    'type'    => 'text',
                    'label'   => '模块标题',
                    'default' => 'LONGOS Payment Security Sandbox',
                ]],
            ];

            return $data;
        });

        add_hook_filter('admin.design.index.data', function (array $data): array {
            $data['editor_app_embeds'][] = [
                'code'            => 'beikeshop_test_sandbox',
                'name'            => 'TEST 支付安全沙盒',
                'description'     => '启用后可在页面构建器中添加 TEST-only 结账安全演示模块。',
                'default_enabled' => false,
            ];

            return $data;
        });
    }
}
