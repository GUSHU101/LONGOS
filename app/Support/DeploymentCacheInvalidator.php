<?php

namespace App\Support;

/**
 * Discards generated Laravel caches as soon as a panel/file deployment changes
 * a file that can affect bootstrapping or Blade rendering. This runs before
 * the application is created, so a newly uploaded .env cannot be masked by
 * bootstrap/cache/config.php and a replaced page cannot be rendered from an
 * incompatible compiled Blade file.
 */
final class DeploymentCacheInvalidator
{
    private const MARKER_FILE = 'longos-bootstrap.fingerprint';

    private const CHECK_FILE = 'longos-bootstrap.checked';

    private const CHECK_INTERVAL_SECONDS = 15;

    public static function refresh(string $basePath, bool $force = false): void
    {
        $cachePath = $basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'cache';
        if (! is_dir($cachePath) || ! is_writable($cachePath)) {
            return;
        }

        // Fingerprinting first-party PHP and Blade files protects panel-based
        // deployments, but doing that recursive scan for every storefront hit
        // adds avoidable latency. Touch before scanning so concurrent workers
        // coalesce around one check; a failed scan is retried after this short
        // interval and a newly uploaded release becomes active shortly after
        // the panel has finished replacing its files.
        $checkPath = $cachePath . DIRECTORY_SEPARATOR . self::CHECK_FILE;
        $checkedAt = is_file($checkPath) ? (int) @filemtime($checkPath) : 0;
        $now       = time();
        if (! $force && $checkedAt > 0 && $checkedAt <= $now && ($now - $checkedAt) < self::CHECK_INTERVAL_SECONDS) {
            return;
        }
        @touch($checkPath);

        $fingerprint = self::fingerprint($basePath);
        $markerPath  = $cachePath . DIRECTORY_SEPARATOR . self::MARKER_FILE;
        $previous    = is_file($markerPath) ? trim((string) @file_get_contents($markerPath)) : '';
        if ($previous === $fingerprint) {
            return;
        }

        if (! self::clearBootstrapCaches($cachePath) || ! self::clearCompiledViews($basePath)) {
            // Do not record this revision as handled. A transient permission
            // problem must be retried on the next request instead of leaving
            // the application permanently mixed between release versions.
            return;
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        @file_put_contents($markerPath, $fingerprint . PHP_EOL, LOCK_EX);
    }

    private static function fingerprint(string $basePath): string
    {
        $paths = [
            $basePath . DIRECTORY_SEPARATOR . '.env',
            $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'installed',
            $basePath . DIRECTORY_SEPARATOR . 'composer.lock',
            $basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
            $basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'longos.php',
            $basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php',
            // mix() reads this manifest at runtime. Include it so a release
            // that changes only the rebuilt editor/theme assets still clears
            // generated state before the next admin request.
            $basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'mix-manifest.json',
            $basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Kernel.php',
            $basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'DeploymentCacheInvalidator.php',
        ];

        foreach (['routes', 'config', 'database/migrations'] as $directory) {
            foreach (self::filesWithExtension($basePath . DIRECTORY_SEPARATOR . $directory, 'php') as $path) {
                $paths[] = $path;
            }
        }

        // Shared-hosting panels can replace PHP files without restarting
        // PHP-FPM while OPcache timestamp validation is disabled. Track the
        // first-party runtime layers that directly affect web requests so the
        // first request after an upload activates the new release.
        foreach ([
            'app/Http/Middleware',
            'app/Providers',
            'app/Services',
            'app/Support',
            'beike/Admin/Http/Controllers',
            'beike/Admin/Http/Resources',
            'beike/Admin/Services',
            'beike/Models',
            'beike/Repositories',
            'beike/Services',
            'beike/Shop/Http/Controllers',
            'beike/Shop/Http/Resources',
            'beike/Shop/Services',
            'lang',
            'plugins',
        ] as $directory) {
            foreach (self::filesWithExtension($basePath . DIRECTORY_SEPARATOR . $directory, 'php') as $path) {
                $paths[] = $path;
            }
        }

        // Commerce and plugin routes are loaded outside Laravel's top-level
        // routes directory. Include them so uploading code through a hosting
        // panel cannot leave a valid-looking but stale routes-v7.php behind.
        foreach (['beike', 'plugins'] as $root) {
            foreach (glob($basePath . DIRECTORY_SEPARATOR . $root . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'Routes', GLOB_ONLYDIR) ?: [] as $routeDirectory) {
                foreach (self::filesWithExtension($routeDirectory, 'php') as $path) {
                    $paths[] = $path;
                }
            }
        }

        // Blade files are PHP source from Laravel's perspective. Their cache
        // must be invalidated before boot, otherwise a partial extraction can mix a
        // newly-rendered page script with an older compiled page shell.
        foreach (['resources/beike', 'themes', 'beike/Installer/Views', 'plugins'] as $directory) {
            foreach (self::filesWithExtension($basePath . DIRECTORY_SEPARATOR . $directory, 'blade.php') as $path) {
                $paths[] = $path;
            }
        }

        sort($paths, SORT_STRING);

        $parts = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $parts[] = $path . ':' . filesize($path) . ':' . filemtime($path);
            }
        }

        return hash('sha256', implode('|', $parts));
    }

    private static function clearBootstrapCaches(string $cachePath): bool
    {
        foreach (['config.php', 'routes-v7.php', 'events.php'] as $cacheFile) {
            $path = $cachePath . DIRECTORY_SEPARATOR . $cacheFile;
            if (is_file($path) && ! @unlink($path)) {
                return false;
            }
        }

        return true;
    }

    private static function clearCompiledViews(string $basePath): bool
    {
        $viewsPath = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'views';
        if (! is_dir($viewsPath)) {
            return true;
        }
        if (! is_writable($viewsPath)) {
            return false;
        }

        foreach (glob($viewsPath . DIRECTORY_SEPARATOR . '*.php') ?: [] as $compiledView) {
            if (! @unlink($compiledView)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private static function filesWithExtension(string $directory, string $extension): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.' . $extension)) {
                    $files[] = $file->getPathname();
                }
            }
        } catch (\UnexpectedValueException) {
            // A partially uploaded directory can briefly be unreadable. The
            // next request will retry once the upload has settled.
        }

        return $files;
    }
}
