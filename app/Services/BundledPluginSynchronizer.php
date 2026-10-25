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
     * Runtime packages maintained and shipped as part of LONGOS.
     *
     * Existing status/configuration values are never overwritten. Payment
     * providers default to disabled on new tenants and are only shown at
     * checkout after the merchant saves a complete credential set.
     *
     * @var array<string, array{type: string, settings: array<string, scalar>, tables: list<string>}>
     */
    private const PACKAGES = [
        'flat_shipping' => [
            'type'     => 'shipping',
            'settings' => ['status' => '1', 'type' => 'fixed', 'value' => '0'],
            'tables'   => [],
        ],
        'paypal' => [
            'type'     => 'payment',
            // A payment provider is not usable until its credentials have
            // passed the plugin validator. Never expose a freshly provisioned
            // tenant to a checkout button that can only fail.
            'settings' => ['status' => '0'],
            'tables'   => [],
        ],
        'stripe' => [
            'type'     => 'payment',
            'settings' => ['status' => '0'],
            'tables'   => [],
        ],
        'longos_platform_pay' => [
            'type'     => 'payment',
            'settings' => ['status' => '0', 'share_paypal' => '1', 'share_stripe' => '1', 'stripe_webhook_secret' => ''],
            'tables'   => [],
        ],
        self::PRODUCT_REVIEWS => [
            'type'     => 'feature',
            'settings' => ['status' => '1', 'display_limit' => '6'],
            'tables'   => ['longos_product_reviews'],
        ],
        'longos_live_chat' => [
            'type'     => 'feature',
            'settings' => ['status' => '1', 'retention_days' => '1'],
            'tables'   => ['longos_live_chat_conversations', 'longos_live_chat_messages'],
        ],
        'pixel_tracking' => [
            'type'     => 'feature',
            'settings' => ['status' => '0'],
            'tables'   => ['pixel_tracking_order_contexts'],
        ],
        'shopify_product_transfer' => [
            'type'     => 'feature',
            'settings' => ['status' => '1'],
            'tables'   => [
                'shopify_product_handles',
                'shopify_product_imports',
                'shopify_product_variant_skus',
            ],
        ],
    ];

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

        $created = false;
        foreach (self::PACKAGES as $code => $definition) {
            $plugin = Plugin::query()->firstOrCreate(
                ['code' => $code],
                ['type' => $definition['type']],
            );
            $created = $created || $plugin->wasRecentlyCreated;

            foreach ($definition['settings'] as $name => $value) {
                Setting::query()->firstOrCreate(
                    ['type' => 'plugin', 'space' => $code, 'name' => $name],
                    ['value' => (string) $value, 'json' => false],
                );
            }
        }

        // The plugin manager may already have inspected this tenant before a
        // bundled row was inserted. Invalidate both caches so this same
        // upgrade process sees and migrates the newly registered packages.
        if ($created) {
            PluginRepo::resetRuntimeCache();
            if (app()->bound('plugin')) {
                app('plugin')->forgetRuntimeCache();
            }
        }

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

        if (! Schema::hasTable('plugins') || ! Schema::hasTable('settings')) {
            throw new RuntimeException('Bundled plugins cannot be synchronized before the commerce schema is migrated.');
        }

        $definition = self::PACKAGES[self::PRODUCT_REVIEWS];
        $record     = Plugin::query()->firstOrCreate(
            ['code' => self::PRODUCT_REVIEWS],
            ['type' => $definition['type']],
        );
        foreach ($definition['settings'] as $name => $value) {
            Setting::query()->firstOrCreate(
                ['type' => 'plugin', 'space' => self::PRODUCT_REVIEWS, 'name' => $name],
                ['value' => (string) $value, 'json' => false],
            );
        }
        if ($record->wasRecentlyCreated) {
            PluginRepo::resetRuntimeCache();
            if (app()->bound('plugin')) {
                app('plugin')->forgetRuntimeCache();
            }
        }
        SettingRepo::clearCache();

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
        $this->assertProductReviewsReady();
    }

    public function assertReady(): void
    {
        $missing = [];
        foreach (self::PACKAGES as $code => $definition) {
            try {
                $package = app('plugin')->getPlugin($code);
            } catch (\Throwable) {
                $package = null;
            }
            if ($package === null || $package->getType() !== $definition['type']) {
                $missing[] = "{$code} (package)";
            }

            if (! Plugin::query()->where('code', $code)->exists()) {
                $missing[] = "{$code} (registration)";

                continue;
            }
            foreach ($definition['tables'] as $table) {
                if (! Schema::hasTable($table)) {
                    $missing[] = "{$code} ({$table})";
                }
            }
        }

        if ($missing !== []) {
            throw new RuntimeException('Bundled LONGOS plugins are incomplete: ' . implode(', ', $missing) . '.');
        }
    }

    /** @return array<string, list<string>> */
    public static function requiredTables(): array
    {
        return array_map(
            static fn (array $definition): array => $definition['tables'],
            self::PACKAGES,
        );
    }

    /** @return list<string> */
    public static function packageCodes(): array
    {
        return array_keys(self::PACKAGES);
    }

    public static function isBundled(string $code): bool
    {
        return array_key_exists(strtolower(trim($code)), self::PACKAGES);
    }

    private function assertProductReviewsReady(): void
    {
        if (! Schema::hasTable('longos_product_reviews')) {
            throw new RuntimeException('The bundled LONGOS Product Reviews migration did not create its required table.');
        }
    }
}
