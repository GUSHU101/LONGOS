<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustProxies;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class TrustedProxyTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_authenticated_caddy_proxy_headers_are_trusted(): void
    {
        $token = str_repeat('a', 64);
        config([
            'longos.network.proxy_token'     => $token,
            'longos.network.trusted_proxies' => [],
        ]);
        $request = Request::create('http://shop.example.com/products', 'GET', [], [], [], [
            'REMOTE_ADDR'               => '172.20.0.5',
            'HTTP_X_FORWARDED_FOR'      => '203.0.113.20',
            'HTTP_X_FORWARDED_PROTO'    => 'https',
            'HTTP_X_LONGOS_PROXY_TOKEN' => $token,
        ]);
        $observed = null;

        (new TrustProxies)->handle($request, function (Request $request) use (&$observed) {
            $observed = [
                $request->isSecure(),
                $request->ip(),
                $request->attributes->getBoolean(TrustProxies::CADDY_REQUEST_ATTRIBUTE),
            ];

            return response('ok');
        });

        self::assertSame([true, '203.0.113.20', true], $observed);
    }

    public function test_untrusted_proxy_token_cannot_spoof_forwarded_headers(): void
    {
        config([
            'longos.network.proxy_token'     => str_repeat('a', 64),
            'longos.network.trusted_proxies' => [],
        ]);
        $request = Request::create('http://shop.example.com/products', 'GET', [], [], [], [
            'REMOTE_ADDR'               => '198.51.100.8',
            'HTTP_X_FORWARDED_FOR'      => '203.0.113.20',
            'HTTP_X_FORWARDED_PROTO'    => 'https',
            'HTTP_X_LONGOS_PROXY_TOKEN' => 'wrong',
        ]);
        $observed = null;

        (new TrustProxies)->handle($request, function (Request $request) use (&$observed) {
            $observed = [
                $request->isSecure(),
                $request->ip(),
                $request->attributes->getBoolean(TrustProxies::CADDY_REQUEST_ATTRIBUTE),
            ];

            return response('ok');
        });

        self::assertSame([false, '198.51.100.8', false], $observed);
    }

    public function test_authenticated_caddy_only_trusts_cloudflare_headers_when_edge_peer_is_cloudflare(): void
    {
        $token = str_repeat('b', 64);
        config([
            'longos.network.proxy_token'     => $token,
            'longos.network.trusted_proxies' => [],
        ]);
        $request = Request::create('https://shop.example.com/products', 'GET', [], [], [], [
            'REMOTE_ADDR'                      => '172.20.0.5',
            'HTTP_X_FORWARDED_FOR'             => '198.41.128.20',
            'HTTP_CF_CONNECTING_IP'            => '203.0.113.77',
            'HTTP_X_LONGOS_EDGE_REMOTE'        => '198.41.128.20',
            'HTTP_X_LONGOS_PROXY_TOKEN'        => $token,
        ]);
        $observed = null;

        (new TrustProxies)->handle($request, function (Request $request) use (&$observed) {
            $observed = [
                $request->ip(),
                $request->attributes->getBoolean(\Beike\Services\CloudflareIpRangeService::REQUEST_ATTRIBUTE),
                $request->hasHeader('X-Longos-Proxy-Token'),
                $request->hasHeader('X-Longos-Edge-Remote'),
            ];

            return response('ok');
        });

        self::assertSame(['203.0.113.77', true, false, false], $observed);
    }

    public function test_authenticated_caddy_rejects_forged_cloudflare_header_from_direct_client(): void
    {
        $token = str_repeat('c', 64);
        config([
            'longos.network.proxy_token'     => $token,
            'longos.network.trusted_proxies' => [],
        ]);
        $request = Request::create('https://shop.example.com/products', 'GET', [], [], [], [
            'REMOTE_ADDR'                      => '172.20.0.5',
            'HTTP_X_FORWARDED_FOR'             => '203.0.113.10',
            'HTTP_CF_CONNECTING_IP'            => '192.0.2.99',
            'HTTP_X_LONGOS_EDGE_REMOTE'        => '203.0.113.10',
            'HTTP_X_LONGOS_PROXY_TOKEN'        => $token,
        ]);
        $observed = null;

        (new TrustProxies)->handle($request, function (Request $request) use (&$observed) {
            $observed = [
                $request->ip(),
                $request->attributes->getBoolean(\Beike\Services\CloudflareIpRangeService::REQUEST_ATTRIBUTE),
                $request->hasHeader('CF-IPCountry'),
                $request->hasHeader('CF-Connecting-IP'),
            ];

            return response('ok');
        });

        self::assertSame(['203.0.113.10', false, false, false], $observed);
    }

    public function test_verified_cloudflare_edge_discards_forwarded_ip_when_connecting_ip_is_invalid(): void
    {
        $token = str_repeat('d', 64);
        config([
            'longos.network.proxy_token'     => $token,
            'longos.network.trusted_proxies' => [],
        ]);
        $request = Request::create('https://shop.example.com/products', 'GET', [], [], [], [
            'REMOTE_ADDR'                      => '172.20.0.5',
            'HTTP_X_FORWARDED_FOR'             => '192.0.2.66',
            'HTTP_CF_CONNECTING_IP'            => 'not-an-ip',
            'HTTP_CF_IPCOUNTRY'                => 'JP',
            'HTTP_X_LONGOS_EDGE_REMOTE'        => '198.41.128.20',
            'HTTP_X_LONGOS_PROXY_TOKEN'        => $token,
        ]);
        $observed = null;

        (new TrustProxies)->handle($request, function (Request $request) use (&$observed) {
            $observed = [
                $request->ip(),
                $request->attributes->getBoolean(\Beike\Services\CloudflareIpRangeService::REQUEST_ATTRIBUTE),
                $request->header('CF-IPCountry'),
                $request->hasHeader('CF-Connecting-IP'),
            ];

            return response('ok');
        });

        self::assertSame(['172.20.0.5', true, 'JP', false], $observed);
    }
}
