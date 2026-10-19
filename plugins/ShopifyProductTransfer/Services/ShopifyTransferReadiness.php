<?php

namespace Plugin\ShopifyProductTransfer\Services;

use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class ShopifyTransferReadiness
{
    private const REQUIRED_TABLES = [
        'shopify_product_handles'      => ['id', 'product_id', 'handle', 'created_at', 'updated_at'],
        'shopify_product_variant_skus' => [
            'id', 'product_id', 'internal_sku', 'source_sku', 'created_at', 'updated_at',
        ],
        'shopify_product_imports' => [
            'id', 'admin_user_id', 'original_name', 'file_path', 'replace_existing',
            'status', 'processed', 'total', 'result', 'error', 'started_at',
            'finished_at', 'created_at', 'updated_at',
        ],
    ];

    /** @var array<int, array<int, string>> */
    private const REQUIRED_IMPORT_INDEXES = [
        ['admin_user_id', 'created_at'],
        ['status', 'updated_at'],
        ['status', 'finished_at'],
    ];

    /**
     * Repair a deployment where plugin files were replaced before the normal
     * LONGOS upgrade runner applied this plugin's migrations. The migrations
     * are idempotent, and Laravel will still record them on the next normal
     * upgrade because their up() methods safely detect the existing tables.
     */
    public function ensureReady(): void
    {
        if ($this->isReady()) {
            return;
        }

        try {
            $files = glob(dirname(__DIR__) . '/Migrations/*.php') ?: [];
            sort($files);
            foreach ($files as $file) {
                $migration = require $file;
                if (is_object($migration) && method_exists($migration, 'up')) {
                    $migration->up();
                }
            }
        } catch (Throwable $exception) {
            // Two first requests can race immediately after files are
            // deployed. If the other request completed the idempotent schema
            // creation, this request is ready too and should not show an
            // alarming but transient database error.
            if ($this->isReady()) {
                return;
            }

            throw new RuntimeException(
                'Shopify Product Transfer could not prepare its database tables.',
                0,
                $exception,
            );
        }

        if (! $this->isReady()) {
            throw new RuntimeException('Shopify Product Transfer database tables are unavailable.');
        }
    }

    private function isReady(): bool
    {
        foreach (self::REQUIRED_TABLES as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                return false;
            }
        }
        foreach (self::REQUIRED_IMPORT_INDEXES as $columns) {
            if (! Schema::hasIndex('shopify_product_imports', $columns)) {
                return false;
            }
        }

        return true;
    }
}
