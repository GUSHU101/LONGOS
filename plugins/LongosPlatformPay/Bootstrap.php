<?php

namespace Plugin\LongosPlatformPay;

class Bootstrap
{
    public function boot(): void
    {
        add_hook_filter('repo.plugin.payment_methods', static function ($methods) {
            if (! (bool) data_get(current_site()?->meta, 'control_site', false)) {
                return $methods;
            }

            return collect($methods)
                ->reject(static fn ($method): bool => (string) ($method->code ?? '') === 'longos_platform_pay')
                ->values();
        });
    }
}
