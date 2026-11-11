<?php

namespace Plugin\LongosInstantTranslate\Services;

use Beike\Services\CloudflareIpRangeService;
use Illuminate\Http\Request;

final class VerifiedVisitorCountry
{
    public function __construct(private readonly CloudflareIpRangeService $cloudflare) {}

    public function countryCode(Request $request): ?string
    {
        if (! $this->cloudflare->isCloudflareRequest($request)) {
            return null;
        }

        return self::normalize($request->header('CF-IPCountry'));
    }

    public static function normalize(mixed $country): ?string
    {
        if (! is_string($country)) {
            return null;
        }
        $country = strtoupper(trim($country));

        return preg_match('/^[A-Z]{2}$/', $country) === 1
            && ! in_array($country, ['XX', 'EU', 'AP', 'ZZ'], true)
            ? $country
            : null;
    }
}
