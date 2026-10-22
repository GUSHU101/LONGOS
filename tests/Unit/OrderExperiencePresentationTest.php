<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class OrderExperiencePresentationTest extends TestCase
{
    public function test_order_list_uses_one_responsive_accessible_flow(): void
    {
        $root   = dirname(__DIR__, 2);
        $list   = (string) file_get_contents($root . '/themes/default/account/order.blade.php');
        $filter = (string) file_get_contents($root . '/themes/default/account/order_status.blade.php');
        $styles = (string) file_get_contents($root . '/resources/beike/shop/default/css/page-account-order.scss');

        self::assertStringNotContainsString('is_mobile()', $list);
        self::assertStringContainsString('role="listitem"', $list);
        self::assertStringContainsString('data-copy-order=', $list);
        self::assertStringContainsString('data-order-action="complete"', $list);
        self::assertStringContainsString('data-order-action="cancel"', $list);
        self::assertStringContainsString("shop_route('orders.cancel'", $list);
        self::assertStringContainsString('aria-live="polite"', $list);
        self::assertStringContainsString('aria-current="page"', $filter);
        self::assertStringContainsString('@media (max-width: 767.98px)', $styles);
    }

    public function test_order_detail_explains_status_and_protects_customer_actions(): void
    {
        $root       = dirname(__DIR__, 2);
        $detail     = (string) file_get_contents($root . '/themes/default/shared/order_info.blade.php');
        $controller = (string) file_get_contents($root . '/beike/Shop/Http/Controllers/OrderController.php');
        $repository = (string) file_get_contents($root . '/beike/Repositories/OrderRepo.php');
        $javascript = (string) file_get_contents($root . '/resources/beike/shop/default/js/order-actions.js');

        self::assertStringNotContainsString('is_mobile()', $detail);
        self::assertStringContainsString('$canManageOrder', $detail);
        self::assertStringContainsString("session('guest_order_numbers'", $detail);
        self::assertStringContainsString("status_hint_' . \$order->status", $detail);
        self::assertStringContainsString('confirm_receipt_prompt', $detail);
        self::assertStringContainsString('refund_prompt', $detail);
        self::assertStringContainsString('<ol class="order-timeline">', $detail);
        self::assertStringContainsString('rel="noopener noreferrer"', $detail);
        self::assertStringContainsString('StateMachineService::SHIPPED', $controller);
        self::assertStringContainsString('[StateMachineService::UNPAID, StateMachineService::PAID]', $controller);
        self::assertStringContainsString("'orderShipments' => fn", $repository);
        self::assertStringContainsString('window.confirm(confirmation)', $javascript);
        self::assertStringContainsString("button.setAttribute('aria-busy', 'true')", $javascript);
    }

    public function test_order_experience_translations_remain_in_sync(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['order.php', 'order_info.php'] as $file) {
            $english     = require $root . '/lang/en/shop/account/' . $file;
            $chinese     = require $root . '/lang/zh_cn/shop/account/' . $file;
            $englishKeys = array_keys($english);
            $chineseKeys = array_keys($chinese);
            sort($englishKeys);
            sort($chineseKeys);

            self::assertSame($englishKeys, $chineseKeys, $file . ' translation keys differ.');
        }
    }
}
