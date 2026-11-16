<?php

namespace Plugin\LongosBotShield\Services;

use Plugin\LongosBotShield\Support\IpAddress;

class CrawlerVerifier
{
    public function __construct(private readonly GoogleCrawlerRanges $googleRanges) {}

    /**
     * @param array{asn?: string, name?: string, domain?: string} $network
     */
    public function verify(string $family, string $ip, array $network): bool
    {
        $ip = IpAddress::normalize($ip);

        return match ($family) {
            'google' => $this->googleRanges->contains($ip),
            'meta'   => strtoupper((string) ($network['asn'] ?? '')) === 'AS32934',
            default  => false,
        };
    }
}
