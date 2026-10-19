<?php

namespace Tests\Unit;

use App\Saas\Models\Domain;
use App\Saas\Support\DnsTxtAnswer;
use PHPUnit\Framework\TestCase;

class SaasDomainVerificationTest extends TestCase
{
    public function test_txt_challenge_requires_an_exact_value(): void
    {
        $expected = 'longos-site-verification=abc123';

        self::assertTrue(DnsTxtAnswer::contains(['google-site-verification=other', $expected], $expected));
        self::assertTrue(DnsTxtAnswer::contains(['"' . $expected . '"'], $expected));
        self::assertFalse(DnsTxtAnswer::contains(['longos-site-verification=abc1234'], $expected));
        self::assertFalse(DnsTxtAnswer::contains([], $expected));
    }

    public function test_pending_domain_exposes_a_dns_txt_challenge_without_serializing_the_raw_token(): void
    {
        $domain = new Domain([
            'host'                => 'shop.example.com',
            'status'              => Domain::STATUS_PENDING,
            'origin'              => Domain::ORIGIN_TENANT,
            'verification_method' => Domain::VERIFICATION_DNS_TXT,
            'verification_token'  => 'secret-token',
        ]);

        self::assertSame('_longos-challenge.shop.example.com', $domain->verification_record_name);
        self::assertSame('longos-site-verification=secret-token', $domain->verification_record_value);
        self::assertArrayNotHasKey('verification_token', $domain->toArray());
    }
}
