<?php

namespace Beike\Shop\Http\Controllers;

use Beike\Services\SitemapService;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function show(): Response
    {
        return SitemapService::render();
    }

    public function robots(): Response
    {
        $adminPath       = '/' . trim(admin_name(), '/');
        $disallowedPaths = [
            $adminPath,
            '/account',
            '/carts',
            '/checkout',
            '/files',
            '/forgotten',
            '/login',
            '/logout',
            '/order-tracking',
            '/orders',
            '/products/autocomplete',
            '/products/search',
            '/register',
            '/user_country',
        ];

        $lines = ['User-agent: *'];
        foreach (array_unique($disallowedPaths) as $path) {
            $lines[] = 'Disallow: ' . $path;
        }
        $lines[] = 'Sitemap: ' . shop_route('sitemap');

        return response(implode("\n", $lines) . "\n", 200, [
            'Content-Type'              => 'text/plain; charset=UTF-8',
            'Cache-Control'             => 'public, max-age=300',
            'X-Content-Type-Options'    => 'nosniff',
        ]);
    }
}
