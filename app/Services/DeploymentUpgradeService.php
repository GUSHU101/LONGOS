<?php

namespace App\Services;

use App\Saas\Support\DatabaseReadiness;
use App\Saas\Support\SensitiveValueRedactor;
use Beike\Repositories\PluginRepo;
use Beike\Repositories\SettingRepo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class DeploymentUpgradeService
{
    private const MARKER_FILE = 'longos-deployment.fingerprint';

    private const LOCK_FILE = 'longos-deployment.lock';

    private const FAILURE_FILE = 'longos-deployment.failed';

    /**
     * Apply required application changes once per deployed source revision.
     *
     * This intentionally runs only for installed HTTP applications. Console
     * commands retain Laravel's normal behavior, and a non-blocking file lock
     * prevents simultaneous PHP-FPM workers from running migrations twice.
     */
    public static function run(): void
    {
        if (! config('longos.auto_upgrade', false)
            || config('saas.enabled')
            || ! installed()
            || app()->runningInConsole()) {
            return;
        }

        $directory = storage_path('framework');
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            Log::warning('LONGOS automatic upgrade skipped: storage/framework is not writable.');

            return;
        }

        $lock = @fopen($directory . DIRECTORY_SEPARATOR . self::LOCK_FILE, 'c+');
        if (! $lock || ! @flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            return;
        }

        try {
            $failureFile = $directory . DIRECTORY_SEPARATOR . self::FAILURE_FILE;
            $retryAfter  = max(5, (int) config('longos.upgrade_retry_seconds', 30));
            if (is_file($failureFile) && (time() - (int) file_get_contents($failureFile)) < $retryAfter) {
                return;
            }

            $fingerprint = self::fingerprint();
            $marker      = $directory . DIRECTORY_SEPARATOR . self::MARKER_FILE;
            $current     = is_file($marker) ? trim((string) file_get_contents($marker)) : '';
            if (hash_equals($fingerprint, $current)) {
                return;
            }
            if (! self::sourceIsSettled()) {
                Log::info('LONGOS automatic deployment is waiting for the uploaded files to settle.');

                return;
            }

            self::migrateCore();
            self::migrateInstalledPlugins();
            self::synchronizeLongosDefaults();
            self::ensurePublicStorageLink();

            // Cached routes/config/views can reference files that were replaced
            // by a panel deployment. Clear them only after a successful upgrade.
            Artisan::call('optimize:clear');
            // Some panel deployments keep OPcache timestamp validation off.
            // Clear it once for this source fingerprint so controllers and
            // Blade code cannot remain stale after a successful deployment.
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            file_put_contents($marker, $fingerprint . PHP_EOL, LOCK_EX);
            @unlink($failureFile);
            Log::info('LONGOS automatic deployment upgrade completed.');
        } catch (\Throwable $e) {
            // Do not mark a failed deployment as complete; the next request
            // retries after the host-side issue is corrected. The application
            // continues to return its usual error handling instead of exposing
            // database details to customers.
            Log::error('LONGOS automatic deployment upgrade failed.', [
                'exception' => $e::class,
                'message'   => SensitiveValueRedactor::redact($e->getMessage()),
            ]);
            file_put_contents($directory . DIRECTORY_SEPARATOR . self::FAILURE_FILE, (string) time(), LOCK_EX);
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    private static function migrateCore(): void
    {
        $exitCode = Artisan::call('migrate', [
            '--force'          => true,
            '--no-interaction' => true,
        ]);
        if ($exitCode !== 0) {
            throw new \RuntimeException('Core migration failed: ' . trim(Artisan::output()));
        }

        DatabaseReadiness::assertMigrated((string) config('database.default'));
    }

    private static function migrateInstalledPlugins(): void
    {
        $bundled = app(BundledPluginSynchronizer::class);
        $bundled->synchronize();

        $manager = app('plugin');
        foreach ($manager->getPlugins() as $plugin) {
            if (! $plugin->getInstalled()) {
                continue;
            }

            PluginRepo::migrateDatabase($plugin);
        }

        $bundled->assertReady();
    }

    /** Keep runtime language selection aligned with the single-language UX. */
    private static function synchronizeLongosDefaults(): void
    {
        SettingRepo::storeValue('admin_locale', 'zh_cn');
        SettingRepo::storeValue('locale', 'en');
        SettingRepo::storeValue('currency', 'USD');
    }

    /**
     * Make uploaded product and design assets reachable after a fresh deploy.
     *
     * Some shared hosts intentionally disallow symlinks. That should not make
     * the storefront unavailable, so this is deliberately best-effort.
     */
    private static function ensurePublicStorageLink(): void
    {
        $publicStorage = public_path('storage');
        if (is_link($publicStorage) || is_dir($publicStorage)) {
            return;
        }

        $storagePublic = storage_path('app/public');
        if (! is_dir($storagePublic) && ! @mkdir($storagePublic, 0755, true) && ! is_dir($storagePublic)) {
            Log::warning('LONGOS could not create the public storage directory.');

            return;
        }

        try {
            $exitCode = Artisan::call('storage:link');
            if ($exitCode !== 0) {
                Log::warning('LONGOS could not create the public storage link: ' . trim(Artisan::output()));
            }
        } catch (\Throwable $exception) {
            Log::warning('LONGOS could not create the public storage link.', [
                'exception' => $exception::class,
                'message'   => SensitiveValueRedactor::redact($exception->getMessage()),
            ]);
        }
    }

    private static function fingerprint(): string
    {
        $files = array_merge(
            glob(database_path('migrations/*.php')) ?: [],
            glob(base_path('plugins/*/Migrations/*.php')) ?: [],
            glob(base_path('plugins/*/Routes/*.php')) ?: [],
            glob(base_path('plugins/*/config.json')) ?: [],
            glob(base_path('routes/*.php')) ?: [],
            glob(base_path('beike/*/Routes/*.php')) ?: [],
            glob(base_path('beike/Shop/Http/Controllers/*.php')) ?: [],
            glob(base_path('beike/Admin/Http/Controllers/*.php')) ?: [],
            glob(base_path('beike/Installer/Controllers/*.php')) ?: [],
            glob(base_path('beike/Installer/Helpers/*.php')) ?: [],
            glob(base_path('beike/Installer/Providers/*.php')) ?: [],
            glob(base_path('beike/Installer/Views/*.blade.php')) ?: [],
            glob(base_path('beike/Services/*.php')) ?: [],
            glob(base_path('themes/default/*.blade.php')) ?: [],
            glob(base_path('themes/default/design/*.blade.php')) ?: [],
            glob(base_path('themes/default/layout/*.blade.php')) ?: [],
            glob(base_path('themes/default/product/*.blade.php')) ?: [],
            glob(base_path('themes/default/checkout/*.blade.php')) ?: [],
            glob(base_path('themes/default/shared/*.blade.php')) ?: [],
            glob(base_path('resources/beike/admin/views/pages/plugins/*.blade.php')) ?: [],
            glob(base_path('resources/beike/admin/css/*.scss')) ?: [],
            glob(base_path('resources/beike/admin/views/pages/design/module/*.blade.php')) ?: [],
            glob(base_path('resources/beike/admin/views/pages/design/builder/*.blade.php')) ?: [],
            glob(base_path('resources/beike/admin/views/pages/design/builder/component/*.blade.php')) ?: [],
            glob(base_path('plugins/*/Bootstrap.php')) ?: [],
            glob(base_path('plugins/*/columns.php')) ?: [],
            glob(base_path('plugins/*/Views/admin/*.blade.php')) ?: [],
            glob(base_path('plugins/*/Views/shop/*.blade.php')) ?: [],
            glob(base_path('resources/beike/shop/default/css/*.scss')) ?: [],
            glob(base_path('resources/beike/shop/default/css/*/*.scss')) ?: [],
            [
                base_path('composer.lock'),
                base_path('config/app.php'),
                base_path('config/database.php'),
                base_path('config/installer.php'),
                base_path('config/longos.php'),
                base_path('bootstrap/app.php'),
                base_path('app/Exceptions/Handler.php'),
                base_path('app/Providers/AppServiceProvider.php'),
                base_path('app/Support/DeploymentCacheInvalidator.php'),
                base_path('app/Http/Middleware/SetLocaleInstaller.php'),
                public_path('index.php'),
                public_path('mix-manifest.json'),
                base_path('app/Services/DeploymentUpgradeService.php'),
            ],
            self::sourceFiles()
        );
        sort($files, SORT_STRING);

        $parts = [];
        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }
            $parts[] = str_replace(base_path(), '', $file) . ':' . filesize($file) . ':' . filemtime($file);
        }

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Include every first-party executable/template file in the release
     * fingerprint. This makes an uploaded controller, provider, plugin, or
     * Blade change reset OPcache even when the host disables timestamp checks.
     * The work runs from Laravel's terminate phase, after the response.
     *
     * @return array<int, string>
     */
    private static function sourceFiles(): array
    {
        $files = [];
        $roots = [
            app_path(),
            base_path('beike'),
            base_path('config'),
            base_path('plugins'),
            base_path('resources/beike'),
            base_path('routes'),
            base_path('themes'),
        ];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * Hosting panels often upload a release file-by-file. Do not migrate a
     * half-uploaded revision; defer until source files have been unchanged for
     * the configured interval, then let the next ordinary request finish it.
     */
    private static function sourceIsSettled(): bool
    {
        $seconds = max(0, (int) config('longos.upgrade_settle_seconds', 15));
        if ($seconds === 0) {
            return true;
        }

        $latestChange = 0;
        $paths        = [
            base_path('.env'),
            base_path('app'),
            base_path('config'),
            base_path('database/migrations'),
            base_path('plugins'),
            base_path('resources/beike'),
            base_path('routes'),
            base_path('themes'),
            public_path('mix-manifest.json'),
            public_path('build'),
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $latestChange = max($latestChange, (int) filemtime($path));

                continue;
            }
            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $latestChange = max($latestChange, $file->getMTime());
                }
            }
        }

        return $latestChange === 0 || (time() - $latestChange) >= $seconds;
    }
}
