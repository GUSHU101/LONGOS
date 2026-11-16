<?php

namespace Plugin\LongosBotShield\Support;

class IpAddress
{
    public static function normalize(?string $ip): string
    {
        $ip = trim((string) $ip);
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/iD', $ip, $match) === 1
            && filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $match[1];
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return '';
        }

        return (string) inet_ntop($packed);
    }

    public static function isPublic(string $ip): bool
    {
        $ip = self::normalize($ip);

        return $ip !== '' && filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
