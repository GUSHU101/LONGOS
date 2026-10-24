<?php

namespace Beike\Services;

use InvalidArgumentException;

/**
 * Tenant-scoped storefront redirects used when URLs change during a relaunch.
 *
 * Redirects intentionally accept internal paths only. This prevents the SEO
 * settings screen from becoming an open-redirect configuration surface.
 */
class LegacyRedirectService
{
    public const MAX_REDIRECTS = 500;

    /** @return array<int, array{from: string, to: string}> */
    public static function redirects(): array
    {
        try {
            return self::normalize(system_setting('base.seo_redirects', []));
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    /**
     * Accept the admin textarea syntax (`/old => /new`) and the durable array
     * representation used in system settings.
     *
     * @return array<int, array{from: string, to: string}>
     */
    public static function normalize(mixed $value): array
    {
        if (is_string($value)) {
            $value = self::parseLines($value);
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException(self::message('admin/setting.seo_redirects_invalid'));
        }

        $normalized = [];
        $seen       = [];
        foreach (array_slice($value, 0, self::MAX_REDIRECTS + 1) as $row) {
            if (count($normalized) >= self::MAX_REDIRECTS) {
                throw new InvalidArgumentException(self::message('admin/setting.seo_redirects_limit', ['count' => self::MAX_REDIRECTS]));
            }
            if (! is_array($row)) {
                throw new InvalidArgumentException(self::message('admin/setting.seo_redirects_invalid'));
            }

            $from   = self::normalizeSource($row['from'] ?? '');
            $to     = self::normalizeTarget($row['to'] ?? '');
            $toPath = (string) (parse_url($to, PHP_URL_PATH) ?: '/');
            if ($from === $toPath || isset($seen[$from])) {
                throw new InvalidArgumentException(self::message('admin/setting.seo_redirects_duplicate', ['path' => $from]));
            }

            $normalized[] = ['from' => $from, 'to' => $to];
            $seen[$from]  = true;
        }

        self::assertNoCycles($normalized);

        return self::collapseChains($normalized);
    }

    public static function toTextarea(array $redirects): string
    {
        return implode("\n", array_map(
            static fn (array $row): string => ($row['from'] ?? '') . ' => ' . ($row['to'] ?? ''),
            self::normalize($redirects),
        ));
    }

    public static function targetFor(string $path): ?string
    {
        $path = '/' . ltrim($path, '/');
        $path = $path !== '/' ? rtrim($path, '/') : $path;

        foreach (self::redirects() as $redirect) {
            if ($redirect['from'] === $path) {
                return $redirect['to'];
            }
        }

        return null;
    }

    /** @return array<int, array{from: string, to: string}> */
    private static function parseLines(string $value): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', trim($value)) ?: [] as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s*(?:=>|->)\s*/', $line, 2);
            if (! is_array($parts) || count($parts) !== 2) {
                throw new InvalidArgumentException(self::message('admin/setting.seo_redirects_line_invalid', ['line' => $lineNumber + 1]));
            }
            $rows[] = ['from' => $parts[0], 'to' => $parts[1]];
        }

        return $rows;
    }

    private static function normalizeSource(mixed $value): string
    {
        $path         = self::internalPath($value, false);
        $firstSegment = strtolower(strtok(ltrim($path, '/'), '/') ?: '');
        $reserved     = ['admin', 'api', 'installer', '_longos', 'build', 'cache', 'image', 'vendor', 'storage'];
        $adminPrefix  = app()->bound('config')
            ? strtolower(trim((string) config('beike.admin_name', 'admin'), '/'))
            : 'admin';
        if (in_array($firstSegment, array_unique([...$reserved, $adminPrefix]), true)) {
            throw new InvalidArgumentException(self::message('admin/setting.seo_redirects_reserved', ['path' => $path]));
        }
        if (preg_match('/\.(?:avif|css|gif|ico|jpe?g|js|json|map|mp4|pdf|png|svg|webp|woff2?)$/i', $path)) {
            throw new InvalidArgumentException(self::message('admin/setting.seo_redirects_reserved', ['path' => $path]));
        }

        return $path;
    }

    private static function normalizeTarget(mixed $value): string
    {
        return self::internalPath($value, true);
    }

    private static function internalPath(mixed $value, bool $allowQuery): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value === '' || mb_strlen($value) > 2000 || preg_match('/[\x00-\x1F\x7F\\\\]/', $value) || str_starts_with($value, '//')) {
            throw new InvalidArgumentException(self::message('admin/setting.seo_redirects_invalid'));
        }

        $parts = parse_url($value);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException(self::message('admin/setting.seo_redirects_internal_only'));
        }
        if (! $allowQuery && isset($parts['query'])) {
            throw new InvalidArgumentException(self::message('admin/setting.seo_redirects_source_query'));
        }

        $path = '/' . ltrim((string) ($parts['path'] ?? ''), '/');
        $path = preg_replace('#/{2,}#', '/', $path) ?: '/';
        $path = $path !== '/' ? rtrim($path, '/') : $path;
        if (str_contains(rawurldecode($path), '..') || mb_strlen($path) > 1000) {
            throw new InvalidArgumentException(self::message('admin/setting.seo_redirects_invalid'));
        }

        return $allowQuery && isset($parts['query']) && $parts['query'] !== ''
            ? $path . '?' . $parts['query']
            : $path;
    }

    /** @param array<int, array{from: string, to: string}> $redirects */
    private static function assertNoCycles(array $redirects): void
    {
        $targets = [];
        foreach ($redirects as $redirect) {
            $targets[$redirect['from']] = (string) (parse_url($redirect['to'], PHP_URL_PATH) ?: '/');
        }

        foreach (array_keys($targets) as $source) {
            $visited = [];
            $path    = $source;
            while (isset($targets[$path])) {
                if (isset($visited[$path])) {
                    throw new InvalidArgumentException(self::message('admin/setting.seo_redirects_cycle', ['path' => $source]));
                }
                $visited[$path] = true;
                $path           = $targets[$path];
            }
        }
    }

    /**
     * Resolve every configured source directly to its final destination. This
     * keeps relaunch redirects to one hop while preserving query parameters
     * that each step in the configured chain would have added or overridden.
     *
     * @param array<int, array{from: string, to: string}> $redirects
     * @return array<int, array{from: string, to: string}>
     */
    private static function collapseChains(array $redirects): array
    {
        $targets = [];
        foreach ($redirects as $redirect) {
            $targets[$redirect['from']] = $redirect['to'];
        }

        return array_map(static function (array $redirect) use ($targets): array {
            $target = $redirect['to'];
            $query  = [];

            while (true) {
                $parts = parse_url($target);
                if (! is_array($parts)) {
                    break;
                }

                if (! empty($parts['query'])) {
                    parse_str($parts['query'], $stepQuery);
                    $query = array_replace($query, $stepQuery);
                }

                $path = (string) ($parts['path'] ?? '/');
                if (! isset($targets[$path])) {
                    $target = $path;

                    break;
                }

                $target = $targets[$path];
            }

            if ($query !== []) {
                $target .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            }

            return ['from' => $redirect['from'], 'to' => $target];
        }, $redirects);
    }

    private static function message(string $key, array $replace = []): string
    {
        if (app()->bound('translator')) {
            return trans($key, $replace);
        }

        $message = $key;
        foreach ($replace as $name => $value) {
            $message = str_replace(':' . $name, (string) $value, $message);
        }

        return $message;
    }
}
