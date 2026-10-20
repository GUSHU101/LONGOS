<?php

namespace App\Services;

class ReleaseOwnedPluginRegistry
{
    /** @var array<string, true>|null */
    private static ?array $cache = null;

    /**
     * Return entries copied from the immutable application image into the
     * persistent plugin volume. The entrypoint writes this manifest before
     * Laravel starts, so runtime installers cannot replace release code.
     *
     * @return array<string, true>
     */
    public static function entries(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $manifest   = base_path('plugins/.longos-release-manifest');
        $entries    = [];
        $candidates = is_file($manifest) && is_readable($manifest)
            ? (file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
            : [];

        // Fail closed if the volume manifest was lost: the immutable seed is
        // still available inside the production image and is authoritative.
        foreach (glob('/opt/longos-seed/plugins/*') ?: [] as $seedPath) {
            $candidates[] = basename($seedPath);
        }

        foreach ($candidates as $entry) {
            $entry = trim((string) $entry);
            if (preg_match('/^[A-Za-z0-9._-]+$/', $entry) !== 1 || $entry === '.' || $entry === '..') {
                continue;
            }
            $entries[strtolower($entry)] = true;
        }

        return self::$cache = $entries;
    }

    public static function owns(string $entry): bool
    {
        $entry = trim(str_replace('\\', '/', $entry), '/');
        if ($entry === '' || str_contains($entry, '/')) {
            return false;
        }

        return isset(self::entries()[strtolower($entry)]);
    }

    public static function archiveEntryIsOwned(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $root = explode('/', $path, 2)[0] ?? '';

        return $root !== '' && self::owns($root);
    }
}
