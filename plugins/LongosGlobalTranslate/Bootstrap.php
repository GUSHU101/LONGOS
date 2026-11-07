<?php

namespace Plugin\LongosGlobalTranslate;

use Plugin\LongosGlobalTranslate\Services\TranslationSchema;
use Plugin\LongosGlobalTranslate\Services\TranslationSettings;
use Plugin\LongosGlobalTranslate\Services\PublishedProductVariantLocalizer;

class Bootstrap
{
    public function boot(): void
    {
        // Product option names are public merchant catalog labels, but they
        // are rendered by Vue after the normal DOM dictionary pass. Supply
        // their reviewed, frozen translations in the initial payload instead
        // of altering select values in the browser. SKU indexes, inventory,
        // prices and every shopper-entered value remain untouched.
        add_hook_filter('resource.product.detail', function (array $payload): array {
            if (current_site() === null) {
                return $payload;
            }

            return app(PublishedProductVariantLocalizer::class)->localize($payload, request());
        });

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
            foreach (['shop.longos_global_translate.config', 'shop.longos_global_translate.locale', 'shop.longos_global_translate.dictionary', 'shop.longos_global_translate.interface-dictionary', 'shop.longos_global_translate.dictionary-batch'] as $route) {
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

            // Every public tenant shop route uses the same published static-
            // label dictionary. The browser applies an exact allowlist only,
            // so broad route coverage does not turn this into a DOM scraper
            // and cannot submit customer, order, address, amount or payment
            // data to a translation provider. The separate control-hosted
            // platform-payment screen is rendered server-side by
            // PlatformPaymentLocaleBridge; it cannot use these tenant-host
            // relative browser endpoints.
            $commerceUi = str_starts_with($routeName, 'shop.');

            return $output . view('LongosGlobalTranslate::shop.widget', compact('settings', 'commerceUi'))->render();
        });
    }
}
