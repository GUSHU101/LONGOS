<?php

namespace Plugin\LongosProductReviews;

use Illuminate\Support\Facades\Schema;
use Plugin\LongosProductReviews\Services\ReviewService;

class Bootstrap
{
    public function boot(): void
    {
        add_hook_blade('layout.header.code', function ($callback, $output, $data): string {
            if (request()->boolean('iframe') || ! request()->routeIs('shop.*')) {
                return $output;
            }

            $assetView = request()->routeIs('shop.products.show')
                ? 'LongosProductReviews::shop.assets'
                : 'LongosProductReviews::shop.card_assets';

            return $output . view($assetView)->render();
        });

        $reviewService = app(ReviewService::class);
        add_hook_blade('product_list.item.name.after', function ($callback, $output, $data) use ($reviewService): string {
            static $reviewTableExists;

            $productId = (int) data_get($data, 'data.product.id', 0);
            if ($productId < 1) {
                return $output;
            }

            $reviewTableExists ??= Schema::hasTable('longos_product_reviews');
            if (! $reviewTableExists) {
                return $output;
            }

            $productIds = $this->productIdsForCardGrid((array) data_get($data, 'data', []), $productId);
            $summary    = $reviewService->storefrontSummaries($productIds)[$productId] ?? null;
            if ($summary === null) {
                return $output;
            }

            return $output . view('LongosProductReviews::shop.card_rating', [
                'summary'    => $summary,
                'productUrl' => (string) data_get($data, 'data.product.url', '#'),
            ])->render();
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

    /**
     * Collect the current grid's product IDs from the common storefront view
     * shapes. The first rendered card primes one batched summary query for the
     * whole grid; subsequent cards use ReviewService's request-local cache.
     *
     * @param array<string, mixed> $viewData
     * @return list<int>
     */
    private function productIdsForCardGrid(array $viewData, int $currentProductId): array
    {
        $ids = [$currentProductId => true];

        foreach ([
            data_get($viewData, 'products_format'),
            data_get($viewData, 'items'),
            data_get($viewData, 'recommended'),
            data_get($viewData, 'products'),
            data_get($viewData, 'content.products'),
        ] as $candidate) {
            $this->collectProductIds($candidate, $ids);
            if (count($ids) >= 250) {
                break;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /** @param array<int, true> $ids */
    private function collectProductIds(mixed $value, array &$ids, int $depth = 0): void
    {
        if ($value === null || $depth > 3 || count($ids) >= 250) {
            return;
        }

        if ($value instanceof \Illuminate\Contracts\Pagination\Paginator) {
            $value = $value->items();
        } elseif ($value instanceof \Illuminate\Support\Collection) {
            $value = $value->all();
        } elseif (is_object($value)) {
            $id = (int) ($value->id ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        $id = (int) ($value['id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = true;

            return;
        }

        foreach ($value as $item) {
            $this->collectProductIds($item, $ids, $depth + 1);
            if (count($ids) >= 250) {
                return;
            }
        }
    }
}
