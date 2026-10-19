<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TenantSitemapContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_storefront_exposes_request_aware_sitemap_and_robots_routes(): void
    {
        $routes = $this->read('beike/Shop/Routes/shop.php');

        self::assertStringContainsString("Route::get('sitemap.xml', [SitemapController::class, 'show'])->name('sitemap')", $routes);
        self::assertStringContainsString("Route::get('robots.txt', [SitemapController::class, 'robots'])->name('robots')", $routes);
        self::assertFileDoesNotExist($this->root . '/public/robots.txt');
    }

    public function test_sitemap_indexes_sellable_content_and_never_authentication_pages(): void
    {
        $service = $this->read('beike/Services/SitemapService.php');

        self::assertStringContainsString("shop_route('home.index')", $service);
        self::assertStringContainsString('StorePolicyRepo::visiblePolicies()', $service);
        self::assertStringContainsString('$sitemap->model->resetItems()', $service);
        self::assertStringContainsString('->cursor()', $service);

        foreach (['forgotten.index', 'login.index', 'products.search', 'register.index'] as $route) {
            self::assertStringNotContainsString($route, $service);
        }
    }

    public function test_robots_blocks_transactional_routes_and_declares_the_current_tenant_sitemap(): void
    {
        $controller = $this->read('beike/Shop/Http/Controllers/SitemapController.php');

        foreach (['/account', '/carts', '/checkout', '/login', '/orders', '/products/search', '/register'] as $path) {
            self::assertStringContainsString("'{$path}'", $controller);
        }

        self::assertStringContainsString("'Sitemap: ' . shop_route('sitemap')", $controller);
        self::assertStringContainsString("'X-Content-Type-Options'", $controller);
    }

    public function test_static_generation_is_disabled_in_saas_mode(): void
    {
        $command = $this->read('beike/Console/Commands/GenerateSitemap.php');

        self::assertStringContainsString("if (config('saas.enabled'))", $command);
        self::assertStringContainsString('tenant-aware /sitemap.xml endpoint', $command);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->root . '/' . $path);
    }
}
