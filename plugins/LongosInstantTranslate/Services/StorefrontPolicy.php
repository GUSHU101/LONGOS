<?php

namespace Plugin\LongosInstantTranslate\Services;

use Illuminate\Http\Request;

final class StorefrontPolicy
{
    /** @var list<string> */
    public const SAFE_ROOT_SELECTORS = ['header', 'main#main-content', 'footer'];

    /** @var list<string> */
    private const PUBLIC_ROUTE_NAMES = [
        'shop.home.index',
        'shop.products.show',
        'shop.products.search',
        'shop.categories.index',
        'shop.categories.show',
        'shop.brands.index',
        'shop.brands.show',
        'shop.pages.show',
        'shop.page_categories.home',
        'shop.page_categories.show',
    ];

    public function allows(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->ajax() || $request->expectsJson()) {
            return false;
        }

        $routeName = (string) optional($request->route())->getName();

        return in_array($routeName, self::PUBLIC_ROUTE_NAMES, true);
    }
}
