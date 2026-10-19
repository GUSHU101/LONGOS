<?php

namespace Tests\Feature;

use Beike\Services\SitemapService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

class TenantSitemapIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config([
            'saas.enabled'              => true,
            'database.default'          => 'saas',
            'database.connections.saas' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('saas');
        DB::setDefaultConnection('saas');
        self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]));
        self::assertSame(0, Artisan::call('db:seed', ['--force' => true, '--no-interaction' => true]));
        load_settings();
    }

    protected function tearDown(): void
    {
        DB::disconnect('saas');
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_sitemap_urls_are_rebuilt_for_each_tenant_host(): void
    {
        $first = $this->renderForHost('alpha.example.test');
        self::assertStringContainsString('https://alpha.example.test/', $first);
        self::assertStringContainsString('/products/1', $first);
        self::assertStringNotContainsString('/login', $first);
        self::assertStringNotContainsString('/register', $first);

        $second = $this->renderForHost('beta.example.test');
        self::assertStringContainsString('https://beta.example.test/', $second);
        self::assertStringNotContainsString('alpha.example.test', $second);
    }

    private function renderForHost(string $host): string
    {
        $request = Request::create('https://' . $host . '/sitemap.xml');
        app()->instance('request', $request);
        app('url')->setRequest($request);

        $response     = SitemapService::render();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=300', $cacheControl);
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        return (string) $response->getContent();
    }
}
