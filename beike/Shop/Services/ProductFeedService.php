<?php

namespace Beike\Shop\Services;

use Beike\Repositories\ProductRepo;
use Beike\Shop\Http\Resources\ProductSimple;

/**
 * First-party automatic product feeds shared by storefront pages and Builder.
 *
 * Keeping these queries in core means a published design never depends on an
 * optional plugin being enabled. Every public limit is bounded again here so
 * imported or hand-edited design JSON cannot create an unbounded query.
 */
final class ProductFeedService
{
    public function latest(int $limit = 8): array
    {
        return $this->feed('latest', $limit);
    }

    public function bestsellers(int $limit = 8): array
    {
        return $this->feed('bestseller', $limit);
    }

    public function paginateLatest(int $perPage): array
    {
        $perPage  = min(96, max(1, $perPage));
        $products = $this->builder('latest')->paginate($perPage)->withQueryString();

        return [
            'products' => $products,
            'items'    => ProductSimple::collection($products)->jsonSerialize(),
        ];
    }

    private function feed(string $kind, int $limit): array
    {
        $products = $this->builder($kind)
            ->limit(min(24, max(1, $limit)))
            ->get();

        return ProductSimple::collection($products)->jsonSerialize();
    }

    private function builder(string $kind)
    {
        $sort = $kind === 'bestseller' ? 'products.sales' : 'products.created_at';

        return ProductRepo::getBuilder([
            'active' => 1,
            'sort'   => $sort,
            'order'  => 'desc',
        ])
            ->with('inCurrentWishlist')
            // Stable ordering prevents equal sales/creation times from moving
            // cards between requests and causing a visually unstable feed.
            ->orderByDesc('products.id');
    }
}
