<?php

namespace Tests\Feature;

use App\Http\Middleware\ApplySecurityHeaders;
use Beike\Services\CurrencyService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class StorefrontHttpPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app->detectEnvironment(static fn (): string => 'production');
        config(['saas.enabled' => true, 'beike.admin_name' => 'admin']);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_canonical_url_removes_tracking_and_keeps_pagination_and_filters(): void
    {
        $request = Request::create(
            'https://shop.example.test/products?page=2&utm_source=campaign&fbclid=tracking&filter%5Bcolor%5D=blue',
        );
        app()->instance('request', $request);

        self::assertSame(
            'https://shop.example.test/products?filter%5Bcolor%5D=blue&page=2',
            storefront_canonical_url(),
        );
    }

    public function test_checkout_responses_receive_security_no_cache_and_noindex_headers(): void
    {
        $request = Request::create('https://shop.example.test/checkout', 'GET');
        app()->instance('request', $request);

        $response = (new ApplySecurityHeaders)->handle(
            $request,
            static fn (): Response => new Response('checkout'),
        );

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        self::assertSame('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
        self::assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-store', $response->headers->get('Cloudflare-CDN-Cache-Control'));
    }

    public function test_geo_localized_html_cannot_be_cached_by_a_browser_or_cdn(): void
    {
        $request = Request::create('https://shop.example.test/products/example', 'GET');
        $request->attributes->set('longos.currency.localized', true);

        $response = (new ApplySecurityHeaders)->handle(
            $request,
            static fn (): Response => new Response('<html>EUR price</html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        );

        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-store', $response->headers->get('CDN-Cache-Control'));
        self::assertSame('no-store', $response->headers->get('Cloudflare-CDN-Cache-Control'));
        self::assertSame(CurrencyService::POLICY_REVISION, $response->headers->get('X-Longos-Currency-Policy'));
        self::assertNull($response->headers->get('X-Robots-Tag'));
    }

    public function test_non_html_public_assets_are_not_made_private_by_currency_localization(): void
    {
        $request = Request::create('https://shop.example.test/build/app.css', 'GET');
        $request->attributes->set('longos.currency.localized', true);

        $response = (new ApplySecurityHeaders)->handle(
            $request,
            static fn (): Response => new Response('body{}', 200, ['Content-Type' => 'text/css']),
        );

        self::assertFalse(str_contains((string) $response->headers->get('Cache-Control'), 'no-store'));
        self::assertNull($response->headers->get('CDN-Cache-Control'));
        self::assertNull($response->headers->get('X-Longos-Currency-Policy'));
    }

    public function test_geo_localized_api_json_cannot_be_cached_by_a_cdn(): void
    {
        $request = Request::create('https://shop.example.test/api/products', 'GET');
        $request->attributes->set('longos.currency.localized', true);

        $response = (new ApplySecurityHeaders)->handle(
            $request,
            static fn (): Response => new Response('{"currency":"JPY"}', 200, ['Content-Type' => 'application/json']),
        );

        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-store', $response->headers->get('Cloudflare-CDN-Cache-Control'));
    }
}
