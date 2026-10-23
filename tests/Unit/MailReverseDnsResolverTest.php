<?php

namespace Tests\Unit;

use App\Mail\MailReverseDnsResolver;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;

class MailReverseDnsResolverTest extends TestCase
{
    public function test_it_accepts_matching_ptr_answers_and_normalizes_fqdns(): void
    {
        [$resolver, $http] = $this->resolver([
            'https://cloudflare.test/*' => ['Status' => 0, 'Answer' => [['type' => 12, 'data' => 'MailOut.Example.COM.']]],
            'https://google.test/*'     => ['Status' => 0, 'Answer' => [['type' => 12, 'data' => 'mailout.example.com.']]],
        ]);

        $result = $resolver->resolve('192.0.2.1');

        self::assertSame('ok', $result['state']);
        self::assertSame(['mailout.example.com'], $result['targets']);
        self::assertStringContainsString('公开 DNS 已返回 PTR', $result['message']);
        $http->assertSent(fn (Request $request): bool => $request['name'] === '1.2.0.192.in-addr.arpa'
            && $request['type']                                           === 'PTR'
            && $request->hasHeader('Accept', 'application/dns-json'));
    }

    public function test_it_reports_servfail_and_refused_authority_details_instead_of_no_ptr(): void
    {
        [$resolver] = $this->resolver([
            'https://cloudflare.test/*' => [
                'Status'  => 2,
                'Comment' => ['No Reachable Authority at delegation 40.112.64.in-addr.arpa.'],
            ],
            'https://google.test/*' => [
                'Status'  => 5,
                'Comment' => 'Name servers refused query (lame delegation?)',
            ],
        ]);

        $result = $resolver->resolve('64.112.40.130');

        self::assertSame('indeterminate', $result['state']);
        self::assertSame([], $result['targets']);
        self::assertStringContainsString('无法确认 PTR 是否已发布', $result['message']);
        self::assertStringContainsString('SERVFAIL', $result['message']);
        self::assertStringContainsString('REFUSED', $result['message']);
        self::assertStringContainsString('lame delegation', $result['message']);
        self::assertStringNotContainsString('无 PTR', $result['message']);
    }

    public function test_it_distinguishes_a_missing_ptr_from_a_resolver_failure(): void
    {
        [$resolver] = $this->resolver([
            'https://cloudflare.test/*' => ['Status' => 3],
            'https://google.test/*'     => ['Status' => 0, 'Answer' => []],
        ]);

        $result = $resolver->resolve('192.0.2.1');

        self::assertSame('missing', $result['state']);
        self::assertStringContainsString('NXDOMAIN', $result['message']);
        self::assertStringContainsString('NOERROR/NODATA', $result['message']);
    }

    public function test_it_warns_while_public_ptr_propagation_is_inconsistent(): void
    {
        [$resolver] = $this->resolver([
            'https://cloudflare.test/*' => ['Status' => 0, 'Answer' => [['type' => 12, 'data' => 'mailout.example.com.']]],
            'https://google.test/*'     => ['Status' => 2, 'Comment' => 'temporary SERVFAIL'],
        ]);

        $result = $resolver->resolve('192.0.2.1');

        self::assertSame('partial', $result['state']);
        self::assertSame(['mailout.example.com'], $result['targets']);
        self::assertStringContainsString('尚未完全一致', $result['message']);
        self::assertStringContainsString('SERVFAIL', $result['message']);
    }

    public function test_it_rejects_unsafe_or_malformed_provider_results(): void
    {
        $http = new Factory;
        $http->fake([
            'https://google.test/*' => $http->response([
                'Status' => 0,
                'Answer' => [['type' => 12, 'data' => "invalid.example.com.\r\nInjected"]],
            ]),
        ]);
        $resolver = new MailReverseDnsResolver($http, [
            'Unsafe' => 'http://resolver.example.test/query',
            'Google' => 'https://google.test/resolve',
        ], 1);

        $result = $resolver->resolve('192.0.2.1');

        self::assertSame('missing', $result['state']);
        self::assertSame([], $result['targets']);
        $http->assertSentCount(1);
    }

    /**
     * @param array<string, array<string, mixed>> $responses
     * @return array{MailReverseDnsResolver, Factory}
     */
    private function resolver(array $responses): array
    {
        $http  = new Factory;
        $fakes = [];
        foreach ($responses as $pattern => $response) {
            $fakes[$pattern] = $http->response($response);
        }
        $http->fake($fakes);

        return [
            new MailReverseDnsResolver($http, [
                'Cloudflare' => 'https://cloudflare.test/dns-query',
                'Google'     => 'https://google.test/resolve',
            ], 1),
            $http,
        ];
    }
}
