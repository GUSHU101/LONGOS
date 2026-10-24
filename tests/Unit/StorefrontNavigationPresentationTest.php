<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class StorefrontNavigationPresentationTest extends TestCase
{
    public function test_account_and_catalog_dropdowns_use_the_accessible_navigation_contract(): void
    {
        $root       = dirname(__DIR__, 2);
        $header     = (string) file_get_contents($root . '/themes/default/layout/header.blade.php');
        $desktop    = (string) file_get_contents($root . '/themes/default/shared/menu-pc.blade.php');
        $mobile     = (string) file_get_contents($root . '/themes/default/shared/menu-mobile.blade.php');
        $script     = (string) file_get_contents($root . '/resources/beike/shop/default/js/header.js');
        $styles     = (string) file_get_contents($root . '/resources/beike/shop/default/css/navigation-rebuild.scss');
        $stylesheet = (string) file_get_contents($root . '/resources/beike/shop/default/css/app.scss');

        self::assertStringContainsString('id="header-account-menu"', $header);
        self::assertStringContainsString('aria-controls="header-account-menu"', $header);
        self::assertStringContainsString('aria-haspopup="true" aria-expanded="false"', $header);
        self::assertStringContainsString('class="account-menu__identity"', $header);
        self::assertStringContainsString("$('.header-content .nav-item.dropdown')", $script);
        self::assertStringContainsString("event.key !== 'Escape'", $script);
        self::assertStringContainsString('is-menu-dismissed', $script);
        self::assertStringContainsString('.account-menu-wrap > .account-menu', $styles);
        self::assertStringContainsString('.dropdown.is-menu-dismissed > .dropdown-menu', $styles);
        self::assertStringContainsString('class="desktop-menu__grid"', $desktop);
        self::assertStringContainsString('--longos-menu-width: {{ $menuWidth }}rem', $desktop);
        self::assertStringContainsString('.dropdown-menu:not(.w-100)', $styles);
        self::assertStringContainsString('word-break: normal;', $styles);
        self::assertStringContainsString('const positionDesktopMenus = () =>', $script);
        self::assertStringContainsString("menu.style.setProperty('--menu-shift-x'", $script);
        self::assertStringNotContainsString("addClass('position-static')", $script);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        self::assertStringContainsString("@import './navigation-rebuild';", $stylesheet);
        self::assertStringContainsString("shop_route('tracking.index')", $desktop);
        self::assertStringContainsString("shop_route('tracking.index')", $mobile);
        self::assertStringContainsString("equal_route('shop.tracking.index')", $desktop);
        self::assertStringContainsString("equal_route('shop.tracking.index')", $mobile);
        self::assertStringContainsString('aria-current="page"', $desktop);
        self::assertStringContainsString('aria-current="page"', $mobile);
        self::assertStringContainsString('.header-tracking-link[aria-current="page"]', $styles);
        self::assertStringContainsString('header .header-content.fixed { z-index: 1025; }', $styles);
        self::assertStringContainsString('overflow: visible;', $styles);
    }

    public function test_mobile_navigation_uses_the_modern_responsive_drawer_contract(): void
    {
        $root   = dirname(__DIR__, 2);
        $header = (string) file_get_contents($root . '/themes/default/layout/header.blade.php');
        $mobile = (string) file_get_contents($root . '/themes/default/shared/menu-mobile.blade.php');
        $script = (string) file_get_contents($root . '/resources/beike/shop/default/js/header.js');
        $styles = (string) file_get_contents($root . '/resources/beike/shop/default/css/navigation-rebuild.scss');
        $legacy = (string) file_get_contents($root . '/resources/beike/shop/default/css/header.scss');

        self::assertStringContainsString('class="offcanvas offcanvas-start mobile-nav-drawer"', $header);
        self::assertStringContainsString('aria-controls="offcanvas-mobile-menu"', $header);
        self::assertStringContainsString('aria-expanded="false"', $header);
        self::assertStringContainsString('class="mobile-nav-drawer__brand-wrap"', $header);
        self::assertStringContainsString('class="mobile-nav-drawer__footer"', $header);
        self::assertStringContainsString("__('shop/header.close_menu')", $header);
        self::assertStringContainsString('class="mobile-nav" aria-labelledby="mobile-navigation-label"', $mobile);
        self::assertStringContainsString('class="mobile-nav__link mobile-nav__link--disclosure collapsed"', $mobile);
        self::assertStringContainsString('class="mobile-nav__toggle collapsed"', $mobile);
        self::assertStringContainsString("__('shop/header.open_submenu'", $mobile);
        self::assertStringNotContainsString('class="nav-item-text"', $mobile);
        self::assertStringNotContainsString('mobile-nav__number', $mobile);
        self::assertStringNotContainsString('mobile-nav__group-toggle', $mobile);
        self::assertStringContainsString('class="mobile-nav__group-title"', $mobile);
        self::assertStringNotContainsString('mobile-nav-drawer__intro', $header);
        self::assertStringContainsString('bootstrap.Offcanvas.getOrCreateInstance(mobileMenuDrawer)', $script);
        self::assertStringContainsString("mobileMenuTrigger.setAttribute('aria-expanded', 'true')", $script);
        self::assertStringContainsString("mobileMenuTrigger.setAttribute('aria-expanded', 'false')", $script);
        self::assertStringContainsString('#offcanvas-mobile-menu.mobile-nav-drawer', $styles);
        self::assertStringContainsString('width: min(90vw, 23.75rem);', $styles);
        self::assertStringContainsString('@media (max-width: 359.98px)', $styles);
        self::assertStringContainsString('min-height: 3.5rem;', $styles);
        self::assertStringContainsString('#offcanvas-mobile-menu :is(a, button):focus-visible', $styles);
        self::assertStringContainsString('overscroll-behavior: contain;', $styles);
        self::assertStringNotContainsString('radial-gradient', $styles);
        self::assertStringNotContainsString('backdrop-filter: blur', $styles);
        self::assertStringNotContainsString('#offcanvas-mobile-menu', $legacy);

        $english = require $root . '/lang/en/shop/header.php';
        $chinese = require $root . '/lang/zh_cn/shop/header.php';
        self::assertSame(array_keys($english), array_keys($chinese));
    }

    public function test_footer_uses_the_premium_commerce_and_accessibility_contract(): void
    {
        $root   = dirname(__DIR__, 2);
        $footer = (string) file_get_contents($root . '/themes/default/layout/footer.blade.php');
        $styles = (string) file_get_contents($root . '/resources/beike/shop/default/css/footer-rebuild.scss');
        $script = (string) file_get_contents($root . '/resources/beike/shop/default/js/footer.js');

        self::assertStringContainsString('class="site-footer longos-footer"', $footer);
        self::assertStringContainsString('class="longos-footer__protection" role="list"', $footer);
        self::assertStringContainsString('class="longos-footer__protection-item" role="listitem"', $footer);
        self::assertStringContainsString('id="longos-footer-policies"', $footer);
        self::assertStringContainsString('id="longos-footer-support"', $footer);
        self::assertStringContainsString('data-policy-key="{{ $policy[\'key\'] }}"', $footer);
        self::assertStringContainsString('class="longos-footer__payments" role="list"', $footer);
        self::assertStringContainsString('data-footer-contact-open', $footer);
        self::assertStringContainsString('data-footer-contact-dialog role="dialog" aria-modal="true"', $footer);
        self::assertStringContainsString('StorePolicyTemplateService::profile()', $footer);
        self::assertStringContainsString('$policyProfile[\'contact_phone\']', $footer);
        self::assertStringContainsString('$policyProfile[\'business_address\']', $footer);
        self::assertStringContainsString('$policyProfile[\'legal_name\']', $footer);
        self::assertStringNotContainsString('Built on', $footer);
        self::assertStringNotContainsString('longos-footer__platform-note', $footer);
        self::assertStringNotContainsString("config('longos.legal.upstream_name')", $footer);
        self::assertStringContainsString('bi bi-plus-lg', $footer);
        self::assertStringContainsString('.longos-footer__protection', $styles);
        self::assertStringContainsString('grid-template-columns: minmax(0, 1fr);', $styles);
        self::assertStringNotContainsString('scroll-snap-type: x proximity', $styles);
        self::assertStringNotContainsString('scroll-snap-align: start', $styles);
        self::assertStringContainsString('.longos-contact__panel', $styles);
        self::assertStringNotContainsString('longos-footer__platform-note', $styles);
        self::assertStringContainsString(':focus-visible', $styles);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        self::assertStringContainsString("event.key !== 'Escape'", $script);
        self::assertStringContainsString("event.key !== 'Tab'", $script);
        self::assertStringContainsString('opener.focus()', $script);
    }
}
