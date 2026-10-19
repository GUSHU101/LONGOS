<?php

/**
 * SitemapService.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-10-09 17:34:34
 * @modified   2022-10-09 17:34:34
 */

namespace Beike\Services;

use Beike\Models\Brand;
use Beike\Models\Category;
use Beike\Models\Page;
use Beike\Models\Product;
use Beike\Repositories\StorePolicyRepo;
use Illuminate\Http\Response;
use Ultrono\Sitemap\Sitemap;

class SitemapService
{
    /**
     * Build the sitemap for the tenant resolved by the current request.
     * The package sitemap is a shared container binding, so always clear it
     * first to prevent URL leakage between tenants in long-running workers.
     */
    public static function build(): Sitemap
    {
        /** @var Sitemap $sitemap */
        $sitemap = resolve('sitemap');
        $sitemap->model->resetItems();

        $sitemap->add(shop_route('home.index'), null, '1.0', 'daily');
        $sitemap->add(shop_route('brands.index'), null, '0.7', 'weekly');
        $sitemap->add(shop_route('categories.index'), null, '0.8', 'daily');

        foreach (StorePolicyRepo::visiblePolicies() as $policy) {
            $sitemap->add($policy['url'], null, '0.3', 'monthly');
        }

        foreach (Product::query()->where('active', 1)->orderBy('updated_at', 'desc')->cursor() as $item) {
            $sitemap->add(shop_route('products.show', ['product' => $item->id]), self::lastModified($item), '0.9', 'weekly');
        }

        foreach (Brand::query()->where('active', 1)->orderBy('updated_at', 'desc')->cursor() as $item) {
            $sitemap->add(shop_route('brands.show', ['id' => $item->id]), self::lastModified($item), '0.6', 'weekly');
        }

        foreach (Category::query()->where('active', 1)->orderBy('updated_at', 'desc')->cursor() as $item) {
            $sitemap->add(shop_route('categories.show', ['category' => $item->id]), self::lastModified($item), '0.8', 'weekly');
        }

        foreach (Page::query()->where('active', 1)->orderBy('updated_at', 'desc')->cursor() as $item) {
            $sitemap->add(shop_route('pages.show', ['page' => $item->id]), self::lastModified($item), '0.5', 'monthly');
        }

        return $sitemap;
    }

    public static function render(): Response
    {
        $response = self::build()->render('xml');
        $response->headers->set('Cache-Control', 'public, max-age=300');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * Static generation remains available for a legacy single-store install.
     * SaaS storefronts must use the request-aware endpoint instead.
     */
    public static function gen(): void
    {
        self::build()->store('xml', 'sitemap');
    }

    private static function lastModified(object $model): ?string
    {
        return $model->updated_at?->format('c');
    }
}
