<?php

namespace Beike\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Single source of truth for tenant-aware timezone validation and runtime use.
 *
 * Database timestamps remain absolute values. This service controls how they
 * are interpreted by Laravel, workers and the administration interface.
 */
class TimezoneService
{
    private const ALIASES = [
        'PRC'            => 'Asia/Shanghai',
        'Asia/Chongqing' => 'Asia/Shanghai',
        'Asia/Harbin'    => 'Asia/Shanghai',
        'Etc/UTC'        => 'UTC',
        'Etc/GMT'        => 'UTC',
        'GMT'            => 'UTC',
        'GMT0'           => 'UTC',
        'UCT'            => 'UTC',
        'Universal'      => 'UTC',
        'Zulu'           => 'UTC',
    ];

    /** @var array<string, true>|null */
    private static ?array $supported = null;

    public static function isSupported(mixed $timezone): bool
    {
        if (! is_string($timezone) || trim($timezone) === '') {
            return false;
        }

        $timezone = trim($timezone);

        return isset(self::ALIASES[$timezone]) || isset(self::supported()[$timezone]);
    }

    public static function normalize(mixed $timezone, ?string $fallback = null): string
    {
        if (is_string($timezone)) {
            $timezone = trim($timezone);
            if (isset(self::ALIASES[$timezone])) {
                return self::ALIASES[$timezone];
            }
            if (isset(self::supported()[$timezone])) {
                return $timezone;
            }
        }

        if ($fallback !== null && $fallback !== $timezone && self::isSupported($fallback)) {
            return self::normalize($fallback);
        }

        return 'UTC';
    }

    public static function configured(?string $fallback = null): string
    {
        $fallback ??= (string) config('app.timezone', 'UTC');
        $siteTimezone = function_exists('current_site') ? current_site()?->timezone : null;
        $fallback     = self::normalize($siteTimezone, $fallback);

        return self::normalize(system_setting('base.app_timezone', $fallback), $fallback);
    }

    public static function apply(mixed $timezone, ?string $fallback = null): string
    {
        $timezone = self::normalize($timezone, $fallback ?? (string) config('app.timezone', 'UTC'));
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);

        return $timezone;
    }

    public static function applyConfigured(?string $fallback = null): string
    {
        return self::apply(self::configured($fallback), $fallback);
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $options = [];

        foreach (array_merge(['UTC'], DateTimeZone::listIdentifiers(DateTimeZone::ALL)) as $identifier) {
            $identifier = self::normalize($identifier);
            $zone       = new DateTimeZone($identifier);
            $offset     = $zone->getOffset($now);
            $sign       = $offset < 0 ? '-' : '+';
            $absolute   = abs($offset);
            $offsetText = sprintf('%s%02d:%02d', $sign, intdiv($absolute, 3600), intdiv($absolute % 3600, 60));

            $options[$identifier] = [
                'value'   => $identifier,
                'label'   => sprintf('(UTC%s) %s', $offsetText, $identifier),
                '_offset' => $offset,
            ];
        }

        uasort($options, static fn (array $left, array $right): int => [$left['_offset'], $left['value']] <=> [$right['_offset'], $right['value']]);

        return array_values(array_map(static function (array $option): array {
            unset($option['_offset']);

            return $option;
        }, $options));
    }

    /** @return array<string, true> */
    private static function supported(): array
    {
        if (self::$supported === null) {
            self::$supported = array_fill_keys(
                array_merge(['UTC'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC)),
                true,
            );
        }

        return self::$supported;
    }
}
