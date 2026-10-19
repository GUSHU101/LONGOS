<?php

namespace App\Saas;

use Beike\Facades\BeikeHttp\Http;
use Beike\Libraries\Registry;
use Beike\Libraries\Tax;
use Beike\Repositories\BrandRepo;
use Beike\Repositories\CategoryRepo;
use Beike\Repositories\CurrencyRepo;
use Beike\Repositories\FlattenCategoryRepo;
use Beike\Repositories\PageRepo;
use Beike\Repositories\PluginRepo;
use Beike\Repositories\ProductRepo;
use Beike\Services\CurrencyService;
use Beike\Shop\Services\CartService;
use Spatie\Permission\PermissionRegistrar;

/**
 * Clears process-local commerce state whenever the active tenant changes.
 *
 * PHP-FPM starts with a clean request, but queue workers and Octane keep static
 * properties and singleton services alive. Cache prefixes alone cannot isolate
 * those values, so every tenant transition must explicitly discard them.
 */
class TenantRuntimeState
{
    public static function reset(): void
    {
        BrandRepo::resetRuntimeCache();
        CategoryRepo::resetRuntimeCache();
        CurrencyRepo::resetRuntimeCache();
        FlattenCategoryRepo::resetRuntimeCache();
        PageRepo::resetRuntimeCache();
        PluginRepo::resetRuntimeCache();
        ProductRepo::resetRuntimeCache();
        CartService::resetRuntimeCache();
        CurrencyService::resetRuntimeCache();
        Tax::resetRuntimeCache();
        Registry::resetRuntimeState();
        Http::resetRuntimeCache();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->clearPermissionsCollection();
        }
        if (app()->bound('plugin')) {
            app('plugin')->forgetRuntimeCache();
        }
        if (app()->bound('tax')) {
            app()->forgetInstance('tax');
        }
    }
}
