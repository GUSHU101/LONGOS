<?php

namespace Tests\Unit;

use App\Mail\CloudflareMailDnsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class CloudflareSpfPolicyMergeTest extends TestCase
{
    public function test_it_preserves_every_sender_and_emits_one_terminal_policy(): void
    {
        $merged = $this->merge([
            'v=spf1 include:_spf.mx.cloudflare.net ~all',
            'v=spf1 a:mailout.example.com -all',
            'v=spf1 ip4:203.0.113.7 include:_spf.mx.cloudflare.net -all',
        ]);

        self::assertSame(
            'v=spf1 include:_spf.mx.cloudflare.net a:mailout.example.com ip4:203.0.113.7 ~all',
            $merged,
        );
        self::assertSame(1, substr_count(strtolower($merged), 'include:_spf.mx.cloudflare.net'));
    }

    public function test_it_adds_the_longos_mail_host_without_weakening_a_strict_policy(): void
    {
        self::assertSame(
            'v=spf1 include:_spf.example.net a:mailout.example.com -all',
            $this->merge(['v=spf1 include:_spf.example.net -all']),
        );
    }

    public function test_it_fails_closed_for_redirect_semantics(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('redirect=');

        $this->merge(['v=spf1 redirect=_spf.example.net']);
    }

    public function test_it_rejects_the_insecure_allow_everything_policy(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('+all');

        $this->merge(['v=spf1 +all']);
    }

    public function test_it_rejects_more_than_ten_direct_dns_lookup_terms(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('10');

        $includes = implode(' ', array_map(
            static fn (int $index): string => "include:_spf{$index}.example.net",
            range(1, 10),
        ));
        $this->merge(["v=spf1 {$includes} -all"]);
    }

    public function test_it_does_not_misidentify_a_different_spf_version(): void
    {
        $method = new ReflectionMethod(CloudflareMailDnsService::class, 'isSpfPolicy');

        self::assertTrue($method->invoke(null, 'v=spf1 -all'));
        self::assertFalse($method->invoke(null, 'v=spf10 -all'));
    }

    /** @param array<int, string> $policies */
    private function merge(array $policies): string
    {
        $method = new ReflectionMethod(CloudflareMailDnsService::class, 'mergeSpfPolicies');

        return $method->invoke(
            new CloudflareMailDnsService('test-token'),
            $policies,
            'a:mailout.example.com',
            'example.com',
        );
    }
}
