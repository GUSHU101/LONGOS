<?php

namespace App\Services;

use Beike\Models\Plugin;
use Beike\Models\Setting;
use Beike\Repositories\PluginRepo;
use Beike\Repositories\SettingRepo;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BundledPluginSynchronizer
{
    private const PRODUCT_REVIEWS = 'longos_product_reviews';

    /**
     * Register LONGOS-owned plugins that are part of the application itself.
     *
     * This only fills missing rows. An existing tenant's enabled/disabled
     * choice and configured display limit are never overwritten on upgrade.
     * Plugin migrations are deliberately executed by the caller after these
     * rows exist, outside a database transaction, because MySQL DDL commits
     * implicitly.
     */
    public function synchronize(): void
    {
        if (! Schema::hasTable('plugins') || ! Schema::hasTable('settings')) {
            throw new RuntimeException('Bundled plugins cannot be synchronized before the commerce schema is migrated.');
        }

        $plugin = Plugin::query()->firstOrCreate(
            ['code' => self::PRODUCT_REVIEWS],
            ['type' => 'feature'],
        );

        // The plugin manager may already have inspected this tenant before
        // the bundled row was inserted. Invalidate both repository and file
        // manager state so the migration phase cannot keep treating it as an
        // uninstalled plugin for the remainder of a long-running request.
        if ($plugin->wasRecentlyCreated) {
            PluginRepo::resetRuntimeCache();
            if (app()->bound('plugin')) {
                app('plugin')->forgetRuntimeCache();
            }
        }

        Setting::query()->firstOrCreate(
            ['type' => 'plugin', 'space' => self::PRODUCT_REVIEWS, 'name' => 'status'],
            ['value' => '1', 'json' => false],
        );
        Setting::query()->firstOrCreate(
            ['type' => 'plugin', 'space' => self::PRODUCT_REVIEWS, 'name' => 'display_limit'],
            ['value' => '6', 'json' => false],
        );

        SettingRepo::clearCache();
    }

    /**
     * Repair an older deployment where the bundled plugin was registered but
     * its migration was never applied to the active tenant database.
     *
     * This is deliberately scoped to LONGOS' bundled review plugin and is
     * safe to call from an HTTP request: Laravel records the migration, while
     * the migration itself is idempotent when the table already exists.
     */
    public function ensureProductReviewsReady(): void
    {
        if (Schema::hasTable('longos_product_reviews')) {
            return;
        }

        $this->synchronize();
        $plugin = app('plugin')->getPlugin(self::PRODUCT_REVIEWS);
        if ($plugin === null) {
            throw new RuntimeException('The bundled LONGOS Product Reviews package is missing.');
        }

        PluginRepo::migrateDatabase($plugin);

        // Recover from an interrupted/manual deployment that recorded the
        // migration but lost the table afterwards. Laravel correctly skips a
        // recorded migration, so invoke the bundled idempotent migration once
        // as a repair fallback before failing the request.
        if (! Schema::hasTable('longos_product_reviews')) {
            $files = [base_path('plugins/LongosProductReviews/Migrations/2026_10_09_000000_create_longos_product_reviews_table.php')];
            foreach ($files as $file) {
                if (! is_file($file)) {
                    continue;
                }
                $migration = require $file;
                if (is_object($migration) && method_exists($migration, 'up')) {
                    $migration->up();
                }
            }
        }
        $this->assertReady();
    }

    public function assertReady(): void
    {
        if (! Schema::hasTable('longos_product_reviews')) {
            throw new RuntimeException('The bundled LONGOS Product Reviews migration did not create its required table.');
        }
    }
}
