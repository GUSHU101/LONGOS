<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class HttpSecurityHeadersContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_security_headers_are_global_for_panel_and_direct_web_server_deployments(): void
    {
        $kernel = $this->read('app/Http/Kernel.php');
        $source = $this->read('app/Http/Middleware/ApplySecurityHeaders.php');

        self::assertStringContainsString('ApplySecurityHeaders::class', $kernel);
        self::assertStringContainsString("'_longos/pay/*'", $source);
        foreach ([
            'X-Content-Type-Options',
            'X-Frame-Options',
            'Referrer-Policy',
            'Permissions-Policy',
            'X-Permitted-Cross-Domain-Policies',
            'Strict-Transport-Security',
        ] as $header) {
            self::assertStringContainsString($header, $source);
        }
        self::assertStringNotContainsString('includeSubDomains', $this->executableHstsLine($source));
    }

    public function test_private_routes_are_not_cacheable_by_browsers_or_cdn(): void
    {
        $source = $this->read('app/Http/Middleware/ApplySecurityHeaders.php');

        foreach (['account', 'carts', 'checkout', 'forgotten', 'installer', 'login', 'orders', 'register'] as $path) {
            self::assertStringContainsString("'{$path}'", $source);
        }

        self::assertStringContainsString("'Cache-Control', 'no-store, private'", $source);
        self::assertStringContainsString("'CDN-Cache-Control', 'no-store'", $source);
        self::assertStringContainsString("'Cloudflare-CDN-Cache-Control', 'no-store'", $source);
        self::assertStringContainsString("'X-Robots-Tag', 'noindex, nofollow, noarchive'", $source);
        self::assertStringContainsString("'products/search'", $source);
    }

    public function test_coolify_caddy_adds_edge_headers_without_overriding_application_cache_policy(): void
    {
        $compose = $this->read('docker-compose.yml');

        self::assertStringContainsString('caddy_0.header.Strict-Transport-Security', $compose);
        self::assertStringContainsString('caddy_0.header.X-Content-Type-Options', $compose);
        self::assertStringNotContainsString('Cache-Control:', $compose);
        self::assertStringNotContainsString('/sitemap.xml', $compose);
    }

    private function executableHstsLine(string $source): string
    {
        foreach (preg_split('/\R/', $source) ?: [] as $line) {
            if (str_contains($line, "headers->set('Strict-Transport-Security'")) {
                return $line;
            }
        }

        return '';
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->root . '/' . $path);
    }
}
