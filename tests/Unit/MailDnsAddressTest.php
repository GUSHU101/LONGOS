<?php

namespace Tests\Unit;

use App\Mail\MailDiagnosticsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MailDnsAddressTest extends TestCase
{
    public function test_it_builds_ipv4_reverse_dns_name(): void
    {
        self::assertSame('130.40.112.64.in-addr.arpa', $this->reverseName('64.112.40.130'));
    }

    public function test_it_builds_ipv6_reverse_dns_name(): void
    {
        self::assertSame(
            '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.e.f.ip6.arpa',
            $this->reverseName('fe80::1'),
        );
    }

    public function test_it_rejects_invalid_addresses(): void
    {
        self::assertSame('', $this->reverseName('not-an-ip'));
    }

    public function test_spf_health_requires_the_longos_mail_host(): void
    {
        $method  = new ReflectionMethod(MailDiagnosticsService::class, 'spfValidationError');
        $service = new MailDiagnosticsService;

        self::assertNull($method->invoke($service, ['v=spf1 include:_spf.mx.cloudflare.net a:mailout.example.com -all'], 'a:mailout.example.com'));
        self::assertStringContainsString(
            'a:mailout.example.com',
            $method->invoke($service, ['v=spf1 include:_spf.mx.cloudflare.net -all'], 'a:mailout.example.com'),
        );
    }

    public function test_it_extracts_the_dkim_public_key_for_exact_comparison(): void
    {
        $method = new ReflectionMethod(MailDiagnosticsService::class, 'dkimPublicKey');

        self::assertSame('AbC123+/=', $method->invoke(new MailDiagnosticsService, 'v=DKIM1; k=rsa; p=AbC123+/='));
    }

    private function reverseName(string $address): string
    {
        $method = new ReflectionMethod(MailDiagnosticsService::class, 'reverseDnsName');

        return $method->invoke(null, $address);
    }
}
