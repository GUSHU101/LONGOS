<?php

namespace Tests\Unit;

use App\Saas\Contracts\DnsRouteResolver;
use App\Saas\HttpDomainTrafficProbe;
use App\Saas\Models\Domain;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

class SaasHttpDomainTrafficProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config([
            'app.key'                                   => 'base64:' . base64_encode(random_bytes(32)),
            'saas.custom_domains.probe_path'            => '/_longos/domain/probe',
            'saas.custom_domains.probe_timeout_seconds' => 8,
            'saas.dns_targets'                          => ['203.0.113.10'],
            'longos.cloudflare.mode'                    => 'off',
        ]);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_only_signed_204_probe_response_from_longos_is_ready(): void
    {
        $domain = $this->domain();
        Http::fake([
            'https://shop.customer.example/_longos/domain/probe*' => Http::response('', 204, [
                'X-Longos-Domain-Probe' => 'ready',
            ]),
        ]);

        $result = (new HttpDomainTrafficProbe($this->resolver([
            'shop.customer.example' => ['203.0.113.10'],
            '203.0.113.10'          => ['203.0.113.10'],
        ])))->probe($domain);

        self::assertTrue($result['ok']);
        self::assertNull($result['error']);
        Http::assertSent(static fn ($request): bool => str_starts_with(
            $request->url(),
            'https://shop.customer.example/_longos/domain/probe?id=12&signature=',
        ));
    }

    public function test_redirect_or_unrecognized_response_is_not_publication_success(): void
    {
        Http::fake([
            'https://shop.customer.example/_longos/domain/probe*' => Http::response('', 302, [
                'Location' => 'https://unrelated.example/',
            ]),
        ]);

        $result = (new HttpDomainTrafficProbe($this->resolver([
            'shop.customer.example' => ['203.0.113.10'],
            '203.0.113.10'          => ['203.0.113.10'],
        ])))->probe($this->domain());

        self::assertFalse($result['ok']);
        self::assertStringContainsString('HTTP 302', (string) $result['error']);
        self::assertStringContainsString('unrelated.example', (string) $result['error']);
        self::assertStringContainsString('Redirect Rules', (string) $result['error']);
    }

    public function test_cloudflare_526_explains_that_the_origin_certificate_or_caddy_edge_is_missing(): void
    {
        Http::fake([
            'https://shop.customer.example/_longos/domain/probe*' => Http::response('', 526, [
                'Server' => 'cloudflare',
            ]),
        ]);

        $result = (new HttpDomainTrafficProbe($this->resolver([
            'shop.customer.example' => ['203.0.113.10'],
            '203.0.113.10'          => ['203.0.113.10'],
        ])))->probe($this->domain());

        self::assertFalse($result['ok']);
        self::assertStringContainsString('Cloudflare 返回 HTTP 526', (string) $result['error']);
        self::assertStringContainsString('Caddy 已接管公网 443', (string) $result['error']);
        self::assertStringContainsString('Full (strict)', (string) $result['error']);
    }

    public function test_cloudflare_route_warms_verified_origin_before_public_probe(): void
    {
        config([
            'saas.tls.driver'        => 'caddy',
            'longos.cloudflare.mode' => 'optional',
        ]);
        Http::fakeSequence()
            ->push('', 403)
            ->push('', 204, ['X-Longos-Domain-Probe' => 'ready']);

        $result = (new HttpDomainTrafficProbe($this->resolver([
            'shop.customer.example' => ['104.16.0.10'],
            '203.0.113.10'          => ['203.0.113.10'],
        ])))->probe($this->domain());

        self::assertTrue($result['ok']);
        Http::assertSentCount(2);
    }

    public function test_cloudflare_failure_includes_the_origin_redirect_that_prevented_caddy_probe(): void
    {
        config([
            'saas.tls.driver'        => 'caddy',
            'longos.cloudflare.mode' => 'optional',
        ]);
        Http::fakeSequence()
            ->push('', 301, ['Location' => 'https://control.example.com/'])
            ->push('', 526, ['Server' => 'cloudflare']);

        $result = (new HttpDomainTrafficProbe($this->resolver([
            'shop.customer.example' => ['104.16.0.10'],
            '203.0.113.10'          => ['203.0.113.10'],
        ])))->probe($this->domain());

        self::assertFalse($result['ok']);
        self::assertStringContainsString('Cloudflare 返回 HTTP 526', (string) $result['error']);
        self::assertStringContainsString('Caddy 源站预热同时失败', (string) $result['error']);
        self::assertStringContainsString('control.example.com', (string) $result['error']);
    }

    public function test_probe_rejects_dns_rebinding_before_making_an_http_request(): void
    {
        Http::fake();

        $result = (new HttpDomainTrafficProbe($this->resolver([
            'shop.customer.example' => ['169.254.169.254'],
            '203.0.113.10'          => ['203.0.113.10'],
        ])))->probe($this->domain());

        self::assertFalse($result['ok']);
        self::assertStringContainsString('DNS', (string) $result['error']);
        Http::assertNothingSent();
    }

    private function domain(): Domain
    {
        $domain = new Domain([
            'host'    => 'shop.customer.example',
            'site_id' => 7,
            'status'  => Domain::STATUS_ACTIVATING,
            'origin'  => Domain::ORIGIN_TENANT,
        ]);
        $domain->setAttribute($domain->getKeyName(), 12);

        return $domain;
    }

    /** @param array<string, array<int, string>> $records */
    private function resolver(array $records): DnsRouteResolver
    {
        return new class($records) implements DnsRouteResolver
        {
            /** @param array<string, array<int, string>> $records */
            public function __construct(private readonly array $records) {}

            public function resolve(string $host): array
            {
                return $this->records[$host] ?? [];
            }
        };
    }
}
