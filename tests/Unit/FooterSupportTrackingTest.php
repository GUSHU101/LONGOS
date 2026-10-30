<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FooterSupportTrackingTest extends TestCase
{
    public function test_builder_exposes_footer_as_a_first_class_editable_region(): void
    {
        $root     = dirname(__DIR__, 2);
        $index    = (string) file_get_contents($root . '/resources/beike/admin/js/builder/index.tsx');
        $settings = (string) file_get_contents($root . '/resources/beike/admin/js/builder/chrome-settings.tsx');
        $view     = (string) file_get_contents($root . '/resources/beike/admin/views/pages/design/builder/puck.blade.php');
        $footer   = (string) file_get_contents($root . '/themes/default/layout/footer.blade.php');

        self::assertStringContainsString('className="beike-puck-structure__global"', $index);
        self::assertStringContainsString('onEditChrome("footer")', $index);
        self::assertStringContainsString('setStructureContext("chrome")', $index);
        self::assertStringContainsString('onTabChange={setChromeTab}', $index);
        self::assertStringContainsString('<MediaField', $settings);
        self::assertStringContainsString('newSocialLink()', $settings);
        self::assertStringContainsString('修改会自动保存到草稿', $settings);
        self::assertStringContainsString('policySettingsUrl', $settings);
        self::assertStringContainsString('policySettingsUrl:', $view);
        self::assertStringContainsString('longos-footer__brand-logo', $footer);
        self::assertStringNotContainsString("__('shop/footer.bottom_note')", $footer);
        self::assertStringNotContainsString('longos-footer__bottom-note', $footer);
    }

    public function test_uploaded_social_icons_keep_their_original_colours(): void
    {
        $styles = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/beike/shop/default/css/footer-rebuild.scss');

        self::assertStringContainsString('.longos-footer__social img', $styles);
        self::assertStringContainsString('filter: none;', $styles);
        self::assertStringContainsString('opacity: 1;', $styles);
        self::assertStringNotContainsString('filter: brightness(0) invert(1)', $styles);
    }

    public function test_contact_dialog_waits_for_the_footer_dom_and_keeps_accessible_focus_behavior(): void
    {
        $root   = dirname(__DIR__, 2);
        $footer = (string) file_get_contents($root . '/themes/default/layout/footer.blade.php');
        $script = (string) file_get_contents($root . '/resources/beike/shop/default/js/footer.js');

        self::assertStringContainsString('data-footer-contact-open', $footer);
        self::assertStringContainsString('data-footer-contact-dialog role="dialog" aria-modal="true"', $footer);
        self::assertStringContainsString("document.readyState === 'loading'", $script);
        self::assertStringContainsString("document.addEventListener('DOMContentLoaded', initializeFooterContactDialog", $script);
        self::assertStringContainsString("dialog.dataset.contactInitialized === 'true'", $script);
        self::assertStringContainsString("event.key === 'Escape'", $script);
        self::assertStringContainsString("event.key !== 'Tab'", $script);
        self::assertStringContainsString('opener.focus()', $script);
    }

    public function test_footer_tracking_link_uses_a_public_route_instead_of_the_customer_account(): void
    {
        $root   = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($root . '/beike/Shop/Routes/shop.php');
        $footer = (string) file_get_contents($root . '/themes/default/layout/footer.blade.php');

        self::assertStringContainsString("Route::get('order-tracking', [TrackingController::class, 'index'])->name('tracking.index')", $routes);
        self::assertStringContainsString("shop_route('tracking.index')", $footer);
        self::assertStringNotContainsString("shop_route('account.order.index') }}\">{{ __('shop/footer.track_order')", $footer);
        self::assertLessThan(
            strpos($routes, "Route::middleware('checkout_auth:"),
            strpos($routes, "Route::get('order-tracking'")
        );
    }

    public function test_public_tracking_page_follows_the_validated_accessible_tracking_contract(): void
    {
        $root   = dirname(__DIR__, 2);
        $view   = (string) file_get_contents($root . '/themes/default/tracking/index.blade.php');
        $script = (string) file_get_contents($root . '/resources/beike/shop/default/js/order-tracking.js');
        $styles = (string) file_get_contents($root . '/resources/beike/shop/default/css/order-tracking.scss');
        $appCss = (string) file_get_contents($root . '/resources/beike/shop/default/css/app.scss');
        $appJs  = (string) file_get_contents($root . '/resources/beike/shop/default/js/app.js');

        self::assertStringContainsString('<meta name="robots" content="noindex,follow">', $view);
        self::assertStringContainsString('<label for="public-tracking-number">', $view);
        self::assertStringContainsString('minlength="8" maxlength="40"', $view);
        self::assertStringContainsString('required aria-required="true"', $view);
        self::assertStringContainsString('role="alert"', $view);
        self::assertStringContainsString('role="status" aria-live="polite"', $view);
        $english = require $root . '/lang/en/shop/tracking.php';
        self::assertStringContainsString('No account', $english['no_login_title']);
        self::assertStringContainsString('/^[A-Z0-9]{8,40}$/', $script);
        self::assertStringContainsString("window.open('about:blank', '_blank')", $script);
        self::assertStringContainsString('trackingWindow.opener = null', $script);
        self::assertStringContainsString('window.location.hostname', $script);
        self::assertStringContainsString('replaceChildren()', $script);
        self::assertStringContainsString(':focus-visible', $styles);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        self::assertStringContainsString("@import './order-tracking';", $appCss);
        self::assertStringContainsString("import './order-tracking'", $appJs);
    }

    public function test_tracking_translations_stay_in_sync(): void
    {
        $root    = dirname(__DIR__, 2);
        $english = require $root . '/lang/en/shop/tracking.php';
        $chinese = require $root . '/lang/zh_cn/shop/tracking.php';

        self::assertSame([], array_values(array_diff(array_keys($english), array_keys($chinese))));
        self::assertSame([], array_values(array_diff(array_keys($chinese), array_keys($english))));
        self::assertStringContainsString('No account required', $english['no_login_title']);
        self::assertStringContainsString('无需登录', $chinese['no_login_title']);
    }
}
