<?php

namespace Plugin\ShopifyProductTransfer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Bootstrap
{
    public function boot(): void
    {
        add_hook_blade('admin.product.list.content.footer', function ($callback, $output, $data) {
            return $output . view('ShopifyProductTransfer::admin.actions')->render();
        });

        add_hook_action('admin.product.force_delete_trashed.before', function ($productIds): void {
            $productIds = collect((array) $productIds)
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
            if ($productIds === []) {
                return;
            }

            foreach (['shopify_product_handles', 'shopify_product_variant_skus'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->whereIn('product_id', $productIds)->delete();
                }
            }
        });
    }
}
