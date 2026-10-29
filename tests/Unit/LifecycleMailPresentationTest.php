<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LifecycleMailPresentationTest extends TestCase
{
    public function test_shared_mail_layout_is_responsive_and_tenant_branded(): void
    {
        $layout = $this->source('themes/default/layout/mail.blade.php');

        self::assertStringContainsString('name="viewport"', $layout);
        self::assertStringContainsString("site_brand_asset('shop_logo')", $layout);
        self::assertStringContainsString('site_brand_style()', $layout);
        self::assertStringContainsString("@yield('preheader'", $layout);
        self::assertStringContainsString('@media only screen and (max-width: 640px)', $layout);
        self::assertStringNotContainsString('min-width:320px', $layout);
        self::assertStringNotContainsString('#fd560f', strtolower($layout));
    }

    public function test_unpaid_and_paid_orders_have_distinct_honest_actions(): void
    {
        $pending    = $this->source('themes/default/mails/order_new.blade.php');
        $updated    = $this->source('themes/default/mails/order_update.blade.php');
        $newMail    = $this->source('beike/Mail/CustomerNewOrder.php');
        $updateMail = $this->source('beike/Mail/CustomerUpdateOrder.php');

        self::assertStringContainsString('order_payment_url($order, true)', $pending);
        self::assertStringContainsString('order_access_url($order)', $pending);
        self::assertStringContainsString('payment_pending_heading', $pending);
        self::assertStringContainsString("'X-BK-Mail-Type', 'payment_pending'", $newMail);

        self::assertStringContainsString('StateMachineService::PAID', $updateMail);
        self::assertStringContainsString('subject_order_paid', $updateMail);
        self::assertStringContainsString('order_access_url($order)', $updated);
        self::assertStringContainsString('ShipmentService::trackingUrl', $updated);
        self::assertStringContainsString('\\Beike\\Services\\ShipmentService::trackingUrl', $updated);
        self::assertStringContainsString('track_delivery', $updated);
        self::assertStringContainsString('tracking_available_after_dispatch', $updated);
    }

    public function test_registration_return_and_recovery_emails_each_have_one_primary_journey(): void
    {
        $registration = $this->source('themes/default/mails/registration.blade.php');
        $return       = $this->source('themes/default/mails/rma_new.blade.php');
        $recovery     = $this->source('themes/default/mails/abandoned_order_recovery.blade.php');

        self::assertStringContainsString("shop_route('home.index')", $registration);
        self::assertStringContainsString('account_benefits_heading', $registration);
        self::assertSame(1, substr_count($registration, 'class="mail-button"'));

        self::assertStringContainsString("shop_route('account.rma.show'", $return);
        self::assertStringContainsString('\\Beike\\Repositories\\OrderProductRepo::find', $return);
        self::assertStringContainsString('return_next_heading', $return);
        self::assertSame(1, substr_count($return, 'class="mail-button"'));

        self::assertStringContainsString('orderProducts->take(3)', $recovery);
        self::assertStringContainsString('nl2br(e($template[\'message\']))', $recovery);
        self::assertStringContainsString('$recoveryUrl', $recovery);
        self::assertSame(1, substr_count($recovery, 'class="mail-button"'));
    }

    public function test_lifecycle_mail_translations_remain_in_sync(): void
    {
        $english     = require dirname(__DIR__, 2) . '/lang/en/mail.php';
        $chinese     = require dirname(__DIR__, 2) . '/lang/zh_cn/mail.php';
        $englishKeys = array_keys($english);
        $chineseKeys = array_keys($chinese);
        sort($englishKeys);
        sort($chineseKeys);

        self::assertSame($englishKeys, $chineseKeys, 'Lifecycle mail translation keys differ.');

        $englishRecovery     = require dirname(__DIR__, 2) . '/lang/en/shop/order_recovery.php';
        $chineseRecovery     = require dirname(__DIR__, 2) . '/lang/zh_cn/shop/order_recovery.php';
        $englishRecoveryKeys = array_keys($englishRecovery);
        $chineseRecoveryKeys = array_keys($chineseRecovery);
        sort($englishRecoveryKeys);
        sort($chineseRecoveryKeys);

        self::assertSame($englishRecoveryKeys, $chineseRecoveryKeys, 'Recovery mail translation keys differ.');
    }

    public function test_manual_mail_retries_create_a_new_delivery_attempt(): void
    {
        $controller = $this->source('beike/Admin/Http/Controllers/MailLogController.php');

        self::assertStringContainsString("'resend:' . \$mailLog->id . ':' . Str::uuid()", $controller);
        self::assertStringContainsString("->where('delivery_id', \$deliveryId)", $controller);
        self::assertStringNotContainsString("'X-Longos-Delivery-Id', 'resend:' . \$mailLog->id", $controller);
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }
}
