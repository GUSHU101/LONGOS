<?php

namespace Plugin\LongosInstantTranslate;

use Plugin\LongosInstantTranslate\Services\InstantTranslateSettings;
use Plugin\LongosInstantTranslate\Services\StorefrontPolicy;

class Bootstrap
{
    public function boot(): void
    {
        add_hook_filter('admin.components.sidebar.menus', function (array $menus): array {
            if (! app('router')->has(admin_name() . '.longos_instant_translate.index')
                || ! current_user()?->can('plugins_index')) {
                return $menus;
            }

            $menus[] = [
                'route'    => 'longos_instant_translate.index',
                'title'    => trans('LongosInstantTranslate::instant.title'),
                'icon'     => 'bi bi-globe2',
                'prefixes' => ['longos_instant_translate'],
                'children' => [],
            ];

            return $menus;
        });

        add_hook_blade('layout.master.footer.code', function ($callback, string $output, array $data): string {
            if (current_site() === null
                || ! app(InstantTranslateSettings::class)->runtimeEnabled()
                || ! app(StorefrontPolicy::class)->allows(request())
                || ! app('router')->has('shop.longos_instant_translate.context')
                || str_contains($output, 'id="longos-instant-translate"')) {
                return $output;
            }

            $settings               = app(InstantTranslateSettings::class);
            $payload                = $settings->publicPayload();
            $payload['context_url'] = shop_route('longos_instant_translate.context');
            $payload['library_url'] = shop_route('longos_instant_translate.library');

            return $output . view('LongosInstantTranslate::shop.widget', compact('payload'))->render();
        });
    }
}
