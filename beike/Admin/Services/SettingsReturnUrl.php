<?php

namespace Beike\Admin\Services;

/**
 * Keeps the post-save redirect on the current administration settings area.
 */
final class SettingsReturnUrl
{
    public static function resolve(
        mixed $candidate,
        string $origin,
        string $adminName,
        string $fallback
    ): string {
        if (! is_string($candidate)) {
            return $fallback;
        }

        $candidate = trim($candidate);
        if ($candidate === '' || preg_match('/[\x00-\x1F\x7F]/', $candidate)) {
            return $fallback;
        }

        $target = parse_url($candidate);
        $base   = parse_url($origin);
        if (! is_array($target) || ! is_array($base) || ! self::hasSameOrigin($target, $base)) {
            return $fallback;
        }

        $path           = $target['path'] ?? '/';
        $decodedPath    = rawurldecode($path);
        if (str_contains($decodedPath, '\\') || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $decodedPath)) {
            return $fallback;
        }

        $settingsPrefix = '/' . trim($adminName, '/') . '/settings';
        if ($path !== $settingsPrefix && ! str_starts_with($path, $settingsPrefix . '/')) {
            return $fallback;
        }

        return $candidate;
    }

    public static function replaceAdminPrefix(string $url, string $oldAdminName, string $newAdminName): string
    {
        if ($oldAdminName === $newAdminName) {
            return $url;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return $url;
        }

        $path      = $parts['path'] ?? '/';
        $oldPrefix = '/' . trim($oldAdminName, '/');
        if ($path !== $oldPrefix && ! str_starts_with($path, $oldPrefix . '/')) {
            return $url;
        }

        $path = '/' . trim($newAdminName, '/') . substr($path, strlen($oldPrefix));

        return self::buildUrl($parts, $path);
    }

    private static function hasSameOrigin(array $target, array $base): bool
    {
        if (isset($target['user']) || isset($target['pass'])) {
            return false;
        }

        $targetScheme = strtolower((string) ($target['scheme'] ?? ''));
        $baseScheme   = strtolower((string) ($base['scheme'] ?? ''));
        $targetHost   = strtolower((string) ($target['host'] ?? ''));
        $baseHost     = strtolower((string) ($base['host'] ?? ''));
        if (! in_array($targetScheme, ['http', 'https'], true)
            || $targetScheme !== $baseScheme
            || $targetHost === ''
            || $targetHost !== $baseHost) {
            return false;
        }

        return self::effectivePort($target, $targetScheme) === self::effectivePort($base, $baseScheme);
    }

    private static function effectivePort(array $parts, string $scheme): int
    {
        return isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    }

    private static function buildUrl(array $parts, string $path): string
    {
        $scheme = (string) ($parts['scheme'] ?? '');
        $host   = (string) ($parts['host'] ?? '');
        if ($scheme === '' || $host === '') {
            return $path;
        }

        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $url = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }
        $url .= $path;
        if (isset($parts['query'])) {
            $url .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $url .= '#' . $parts['fragment'];
        }

        return $url;
    }
}
