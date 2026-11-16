<?php

namespace Plugin\LongosBotShield\Support;

class CidrMatcher
{
    /** @param iterable<string> $ranges */
    public static function contains(string $ip, iterable $ranges): bool
    {
        $ip      = IpAddress::normalize($ip);
        $address = @inet_pton($ip);
        if ($address === false) {
            return false;
        }

        foreach ($ranges as $range) {
            [$network, $prefix] = self::parse((string) $range);
            if ($network === null || strlen($network) !== strlen($address)) {
                continue;
            }

            if (self::matchesPacked($address, $network, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function invalidEntries(string $value): array
    {
        $entries = preg_split('/[\s,;]+/u', $value) ?: [];
        $invalid = [];
        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            [$network] = self::parse($entry);
            if ($network === null) {
                $invalid[] = $entry;
            }
        }

        return array_values(array_unique($invalid));
    }

    /** @return array{?string, int} */
    private static function parse(string $range): array
    {
        $parts   = explode('/', trim($range), 2);
        $network = @inet_pton($parts[0]);
        if ($network === false) {
            return [null, 0];
        }

        $maximum = strlen($network) * 8;
        if (! isset($parts[1]) || $parts[1] === '') {
            return [$network, $maximum];
        }
        if (! ctype_digit($parts[1])) {
            return [null, 0];
        }

        $prefix = (int) $parts[1];
        if ($prefix < 0 || $prefix > $maximum) {
            return [null, 0];
        }

        return [$network, $prefix];
    }

    private static function matchesPacked(string $address, string $network, int $prefix): bool
    {
        $wholeBytes = intdiv($prefix, 8);
        if ($wholeBytes > 0 && substr($address, 0, $wholeBytes) !== substr($network, 0, $wholeBytes)) {
            return false;
        }

        $remaining = $prefix % 8;
        if ($remaining === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($address[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }
}
