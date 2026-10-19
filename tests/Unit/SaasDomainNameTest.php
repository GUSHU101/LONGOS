<?php

namespace Tests\Unit;

use App\Saas\Support\DomainName;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SaasDomainNameTest extends TestCase
{
    public function test_it_normalizes_a_site_domain_and_scheme(): void
    {
        self::assertSame(
            ['host' => 'shop.example.com', 'scheme' => 'https'],
            DomainName::parse('HTTPS://Shop.Example.com/')
        );
        self::assertSame('127.0.0.1', DomainName::normalize('http://127.0.0.1'));
    }

    public function test_it_rejects_paths_wildcards_and_invalid_hosts(): void
    {
        foreach (['https://example.com/admin', '*.example.com', 'invalid_host'] as $domain) {
            try {
                DomainName::parse($domain);
                self::fail("Expected {$domain} to be rejected.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_it_rejects_ports_in_site_domains(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DomainName::parse('https://shop.example.com:8443');
    }
}
