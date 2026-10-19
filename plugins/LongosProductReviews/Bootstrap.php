<?php

namespace Plugin\LongosProductReviews;

use Illuminate\Support\Facades\Schema;
use Plugin\LongosProductReviews\Services\ReviewService;

class Bootstrap
{
    public function boot(): void
    {
        add_hook_blade('layout.header.code', function ($callback, $output, $data): string {
            if (! request()->routeIs('shop.products.show') || request()->boolean('iframe')) {
                return $output;
            }

            return $output . view('LongosProductReviews::shop.assets')->render();
        });

        add_hook_filter('admin.components.sidebar.menus', function (array $menus): array {
            $productIndex = collect($menus)->search(fn (array $menu): bool => ($menu['route'] ?? '') === 'products.index');
            $item         = [
                'route'    => 'longos_product_reviews.index',
                'title'    => trans('LongosProductReviews::reviews.menu'),
                'icon'     => 'bi bi-star',
                'prefixes' => ['longos_product_reviews'],
                'children' => [],
            ];

            if ($productIndex === false) {
                $menus[] = $item;
            } else {
                array_splice($menus, $productIndex + 1, 0, [$item]);
            }

            return $menus;
        });

        add_hook_blade('admin.product.list.action', function ($callback, $output, $data): string {
            $productId = (int) data_get($data, 'data.product.id', 0);
            if ($productId < 1) {
                return $output;
            }

            return $output . view('LongosProductReviews::admin.product_action', compact('productId'))->render();
        });

        add_hook_blade('admin.product.form.sidebar.card.status.after', function ($callback, $output, $data): string {
            $productId = (int) data_get($data, 'data.product.id', 0);
            if ($productId < 1 || ! Schema::hasTable('longos_product_reviews')) {
                return $output;
            }

            return $output . view('LongosProductReviews::admin.product_summary', [
                'productId' => $productId,
                'count'     => \Plugin\LongosProductReviews\Models\Review::query()->where('product_id', $productId)->count(),
            ])->render();
        });

        add_hook_action('admin.product.force_delete_trashed.before', function ($productIds): void {
            if (! Schema::hasTable('longos_product_reviews')) {
                return;
            }

            app(ReviewService::class)->deleteForProducts((array) $productIds);
        });

    }
}
