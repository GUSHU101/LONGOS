<?php

namespace Plugin\LongosGlobalTranslate;

use Plugin\LongosGlobalTranslate\Services\TranslationSchema;
use Plugin\LongosGlobalTranslate\Services\TranslationSettings;

class Bootstrap
{
    public function boot(): void
    {
        add_hook_filter('admin.components.sidebar.menus', function (array $menus): array {
            if (! app('router')->has(admin_name() . '.longos_global_translate.index')
                || ! current_user()?->can('plugins_index')) {
                return $menus;
            }

            $menus[] = [
                'route'    => 'longos_global_translate.index',
                'title'    => trans('LongosGlobalTranslate::translate.title'),
                'icon'     => 'bi bi-translate',
                'prefixes' => ['longos_global_translate'],
                'children' => [],
            ];

            return $menus;
        });

        add_hook_blade('layout.master.footer.code', function ($callback, $output, $data) {
            // The control plane is never a storefront translation target.
            // It does not own tenant-facing content and must not receive a
            // public locale cookie or dictionary endpoint references.
            if (current_site() === null) {
                return $output;
            }
            // `widget.enabled` is the only storefront switch.  Requiring a
            // second, unrelated visual-builder app-embed flag made a fully
            // configured translation library silently disappear on themes
            // that never expose that old editor setting.
            foreach (['shop.longos_global_translate.config', 'shop.longos_global_translate.locale', 'shop.longos_global_translate.dictionary', 'shop.longos_global_translate.interface-dictionary'] as $route) {
                if (! app('router')->has($route)) {
                    return $output;
                }
            }
            $routeName = (string) optional(request()->route())->getName();
            if (str_contains((string) $output, 'id="longos-global-translate"')) {
                return $output;
            }
            // A previous deployment can have been interrupted between plugin
            // migrations. Do not let an optional widget probe incomplete
            // tables during a storefront request; the admin recovery screen
            // and the next normal Coolify upgrade repair the schema.
            if (! TranslationSchema::ready()) {
                return $output;
            }
            $settings = app(TranslationSettings::class)->all();
            if (! (bool) data_get($settings, 'widget.enabled', false)) {
                return $output;
            }

            // Checkout, account and payment routes receive the same selector
            // as the storefront.  Its commerce-interface mode can replace
            // only reviewed, published static labels; it never reads or
            // translates customer input, price/order fields or provider data.
            $commerceUi = str_starts_with($routeName, 'shop.carts.')
                || str_starts_with($routeName, 'shop.checkout.')
                || str_starts_with($routeName, 'shop.account.')
                || in_array($routeName, [
                    'shop.login.index', 'shop.register.index', 'shop.forgotten.index',
                    'shop.orders.pay', 'shop.orders.show',
                    'platform-pay.show', 'platform-pay.return', 'platform-pay.cancel',
                ], true);

            return $output . view('LongosGlobalTranslate::shop.widget', compact('settings', 'commerceUi'))->render();
        });
    }
}
