<?php

namespace Tests\Unit;

use Beike\Services\CloudflareIpRangeService;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Plugin\LongosInstantTranslate\Services\VerifiedVisitorCountry;

class InstantTranslateCountryTrustTest extends TestCase
{
    public function test_forged_country_header_on_untrusted_request_is_rejected(): void
    {
        $request = Request::create('/products/1', 'GET', [], [], [], [
            'REMOTE_ADDR'       => '203.0.113.45',
            'HTTP_CF_IPCOUNTRY' => 'JP',
        ]);

        self::assertNull((new VerifiedVisitorCountry($this->cloudflare(false)))->countryCode($request));
    }

    public function test_verified_cloudflare_country_is_normalized(): void
    {
        $request = Request::create('/products/1', 'GET', [], [], [], [
            'REMOTE_ADDR'       => '173.245.48.10',
            'HTTP_CF_IPCOUNTRY' => 'de',
        ]);

        self::assertSame('DE', (new VerifiedVisitorCountry($this->cloudflare(true)))->countryCode($request));
    }

    public function test_unknown_and_special_country_values_fail_closed(): void
    {
        foreach (['', 'XX', 'T1', 'EU', 'AP', 'ZZ', 'JPN', 'US,JP'] as $country) {
            self::assertNull(VerifiedVisitorCountry::normalize($country));
        }
    }

    private function cloudflare(bool $trusted): CloudflareIpRangeService
    {
        return new class($trusted) extends CloudflareIpRangeService
        {
            public function __construct(private readonly bool $trusted) {}

            public function isCloudflareRequest(Request $request): bool
            {
                return $this->trusted;
            }
        };
    }
}
