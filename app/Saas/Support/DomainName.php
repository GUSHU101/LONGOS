<?php

namespace App\Saas\Support;

use InvalidArgumentException;

final class DomainName
{
    /**
     * @return array{host: string, scheme: string}
     */
    public static function parse(string $value, string $defaultScheme = 'https'): array
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('域名不能为空。');
        }

        $url    = str_contains($value, '://') ? $value : $defaultScheme . '://' . $value;
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? $defaultScheme));
        $host   = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('域名协议仅支持 http 或 https。');
        }
        if ($host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || ! empty($parts['query']) || ! empty($parts['fragment'])) {
            throw new InvalidArgumentException("无效域名：{$value}");
        }
        if (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true)) {
            throw new InvalidArgumentException("域名不能包含路径：{$value}");
        }
        if (str_contains($host, '*')) {
            throw new InvalidArgumentException("站点绑定需要使用确切域名：{$value}");
        }

        if (function_exists('idn_to_ascii') && ! preg_match('/^[\x20-\x7f]+$/', $host)) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii)) {
                $host = strtolower($ascii);
            }
        }

        if (! self::isValidHost($host)) {
            throw new InvalidArgumentException("无效域名：{$value}");
        }

        return ['host' => $host, 'scheme' => $scheme];
    }

    public static function normalize(string $value): string
    {
        return self::parse($value)['host'];
    }

    private static function isValidHost(string $host): bool
    {
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (strlen($host) > 253 || ! str_contains($host, '.')) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63 || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i', $label)) {
                return false;
            }
        }

        return true;
    }
}
