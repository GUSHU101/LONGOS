<?php

namespace Beike\Admin\Services;

use Beike\Models\Brand;
use Beike\Models\Category;
use Beike\Models\Product;
use Beike\Repositories\CategoryRepo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ProductCategoryAssignmentService
{
    private const MAX_SMART_SCAN = 25_000;

    /**
     * A file-by-file development deployment can briefly expose new controllers before
     * the matching tenant migration finishes. Keep ordinary category/product
     * management available throughout that rolling window.
     */
    public function rulesAvailable(): bool
    {
        try {
            return Schema::hasTable('categories') && Schema::hasColumns('categories', [
                'auto_assign_enabled',
                'auto_assign_keywords',
                'auto_assign_brand_ids',
            ]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Add products to categories without removing their existing categories.
     *
     * @param array<int, int|string> $productIds
     * @param array<int, int|string> $categoryIds
     * @return array<string, int>
     */
    public function assign(array $productIds, array $categoryIds): array
    {
        $productIds  = $this->normalizeIds($productIds);
        $categoryIds = $this->normalizeIds($categoryIds);

        if ($productIds === [] || $categoryIds === []) {
            throw ValidationException::withMessages([
                'categories' => trans('admin/product.batch_category_required'),
            ]);
        }

        $validProductIds = Product::query()
            ->whereIn('id', $productIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $validCategoryIds = Category::query()
            ->whereIn('id', $categoryIds)
            ->where('active', true)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if (count($validProductIds) !== count($productIds)) {
            throw ValidationException::withMessages([
                'product_ids' => trans('admin/product.batch_category_invalid_products'),
            ]);
        }
        if (count($validCategoryIds) !== count($categoryIds)) {
            throw ValidationException::withMessages([
                'category_ids' => trans('admin/product.batch_category_invalid_categories'),
            ]);
        }

        return DB::transaction(function () use ($validProductIds, $validCategoryIds): array {
            $existing = DB::table('product_categories')
                ->whereIn('product_id', $validProductIds)
                ->whereIn('category_id', $validCategoryIds)
                ->get(['product_id', 'category_id'])
                ->mapWithKeys(static fn ($row): array => [
                    ((int) $row->product_id) . ':' . ((int) $row->category_id) => true,
                ])
                ->all();

            $now  = now();
            $rows = [];
            foreach ($validProductIds as $productId) {
                foreach ($validCategoryIds as $categoryId) {
                    if (isset($existing[$productId . ':' . $categoryId])) {
                        continue;
                    }

                    $rows[] = [
                        'product_id'  => $productId,
                        'category_id' => $categoryId,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ];
                }
            }

            $inserted = 0;
            foreach (array_chunk($rows, 500) as $chunk) {
                // The unique index added by LONGOS also makes concurrent
                // requests safe. The pre-read keeps this idempotent during a
                // rolling deployment before every tenant migration finishes.
                $inserted += DB::table('product_categories')->insertOrIgnore($chunk);
            }

            $result = [
                'products'   => count($validProductIds),
                'categories' => count($validCategoryIds),
                'inserted'   => $inserted,
                'existing'   => count($validProductIds) * count($validCategoryIds) - $inserted,
            ];

            hook_action('admin.product.categories.batch.after', $result);

            return $result;
        });
    }

    /**
     * Build a deterministic, local-only product recommendation preview.
     * No product data is sent to an external AI or paid service.
     *
     * @return array<string, mixed>
     */
    public function suggest(
        Category $category,
        string|array|null $keywords = null,
        ?array $brandIds = null,
        int $limit = 100
    ): array {
        if (! $category->active) {
            throw ValidationException::withMessages([
                'category' => trans('admin/category.smart_category_disabled'),
            ]);
        }

        $limit = max(1, min(200, $limit));
        $category->loadMissing('descriptions');
        $profile = $this->ruleProfile($category, $keywords, $brandIds);

        if ($profile['keywords'] === [] && $profile['brand_ids'] === []) {
            return $this->emptySuggestion($category, $profile);
        }

        $query = Product::query()
            ->with(['descriptions:id,product_id,locale,name', 'brand:id,name'])
            ->whereDoesntHave('categories', static function ($query) use ($category): void {
                $query->where('categories.id', $category->id);
            })
            ->orderBy('products.id');

        $eligible = (clone $query)->count();
        $scanned  = 0;
        $matches  = [];

        $query->chunkById(250, function ($products) use (&$scanned, &$matches, $profile): bool {
            foreach ($products as $product) {
                if ($scanned >= self::MAX_SMART_SCAN) {
                    return false;
                }

                $scanned++;
                $match = $this->scoreProduct($product, $profile);
                if ($match['score'] === 0) {
                    continue;
                }

                $description = $product->descriptions->firstWhere('locale', locale())
                    ?? $product->descriptions->first();
                $matches[] = [
                    'id'               => (int) $product->id,
                    'name'             => (string) ($description?->name ?? ('#' . $product->id)),
                    'image'            => image_origin($product->image),
                    'active'           => (bool) $product->active,
                    'score'            => $match['score'],
                    'matched_keywords' => $match['terms'],
                ];
            }

            return $scanned < self::MAX_SMART_SCAN;
        }, 'products.id', 'id');

        usort($matches, static fn (array $left, array $right): int => [$right['score'], $right['id']] <=> [$left['score'], $left['id']]
        );

        $totalMatches = count($matches);

        return [
            'category'     => [
                'id'   => (int) $category->id,
                'name' => (string) ($category->descriptions->firstWhere('locale', locale())?->name
                    ?? $category->descriptions->first()?->name
                    ?? ('#' . $category->id)),
            ],
            'rule'          => $profile,
            'candidates'    => array_slice($matches, 0, $limit),
            'scanned'       => $scanned,
            'eligible'      => $eligible,
            'total_matches' => $totalMatches,
            'returned'      => min($limit, $totalMatches),
            'truncated'     => $eligible > $scanned || $totalMatches > $limit,
        ];
    }

    /**
     * Persist the rule used for future product create/update operations.
     *
     * @return array{keywords: array<int, string>, brand_ids: array<int, int>, enabled: bool}
     */
    public function saveRule(Category $category, string|array|null $keywords, array $brandIds, bool $enabled): array
    {
        $keywordList = $this->keywordList($keywords);
        $brandIds    = $this->normalizeIds($brandIds);
        $validBrands = Brand::query()
            ->whereIn('id', $brandIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if (count($validBrands) !== count($brandIds)) {
            throw ValidationException::withMessages([
                'brand_ids' => trans('admin/category.smart_invalid_brands'),
            ]);
        }
        if ($enabled && $keywordList === [] && $validBrands === []) {
            throw ValidationException::withMessages([
                'keywords' => trans('admin/category.smart_rule_required'),
            ]);
        }

        $category->forceFill([
            'auto_assign_enabled'   => $enabled,
            'auto_assign_keywords'  => implode(', ', $keywordList),
            'auto_assign_brand_ids' => $validBrands,
        ])->save();
        CategoryRepo::clearCache();

        $rule = [
            'keywords'  => $keywordList,
            'brand_ids' => $validBrands,
            'enabled'   => $enabled,
        ];
        hook_action('admin.category.auto_assignment_rule.saved', [
            'category' => $category,
            'rule'     => $rule,
        ]);

        return $rule;
    }

    /** @return array<string, int> */
    public function autoAssign(Product $product): array
    {
        $empty = ['products' => 1, 'categories' => 0, 'inserted' => 0, 'existing' => 0];
        if (! $this->rulesAvailable()) {
            return $empty;
        }

        $product->loadMissing(['descriptions:id,product_id,locale,name', 'brand:id,name']);
        $categoryIds = [];
        Category::query()
            ->where('active', true)
            ->where('auto_assign_enabled', true)
            ->with('descriptions:id,category_id,locale,name,meta_keywords')
            ->chunkById(250, function ($categories) use ($product, &$categoryIds): void {
                foreach ($categories as $category) {
                    $profile = $this->ruleProfile($category, null, null, false);
                    if ($this->scoreProduct($product, $profile)['score'] > 0) {
                        $categoryIds[] = (int) $category->id;
                    }
                }
            });

        if ($categoryIds === []) {
            return $empty;
        }

        $result = $this->assign([(int) $product->id], $categoryIds);
        hook_action('admin.product.categories.auto_assigned', [
            'product' => $product,
            'result'  => $result,
        ]);

        return $result;
    }

    /** @param array<int, int|string> $ids @return array<int, int> */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * @return array{keywords: array<int, string>, brand_ids: array<int, int>, brand_names: array<int, string>}
     */
    private function ruleProfile(
        Category $category,
        string|array|null $keywords,
        ?array $brandIds,
        bool $defaultToCategoryName = true
    ): array {
        if ($keywords === null) {
            $keywords = (string) ($category->auto_assign_keywords ?? '');
            if ($keywords === '' && $defaultToCategoryName) {
                $keywords = $category->descriptions
                    ->flatMap(static fn ($description): array => [
                        $description->name,
                        $description->meta_keywords,
                    ])
                    ->filter()
                    ->implode(',');
            }
        }
        $brandIds ??= (array) ($category->auto_assign_brand_ids ?? []);
        $brandIds   = $this->normalizeIds($brandIds);
        $brandNames = Brand::query()
            ->whereIn('id', $brandIds)
            ->pluck('name', 'id')
            ->mapWithKeys(static fn ($name, $id): array => [(int) $id => (string) $name])
            ->all();

        return [
            'keywords'    => $this->keywordList($keywords),
            'brand_ids'   => array_keys($brandNames),
            'brand_names' => $brandNames,
        ];
    }

    /** @param array{keywords: array<int, string>, brand_ids: array<int, int>, brand_names: array<int, string>} $profile @return array{score: int, terms: array<int, string>} */
    private function scoreProduct(Product $product, array $profile): array
    {
        $productName = $this->normalize($product->descriptions->pluck('name')->implode(' '));
        $score       = 0;
        $matched     = [];
        foreach ($profile['keywords'] as $keyword) {
            if ($this->containsTerm($productName, $keyword)) {
                $score += $productName === $keyword ? 100 : 75;
                $matched[] = trans('admin/category.smart_match_name', ['value' => $keyword]);
            }
        }

        $brandId = (int) ($product->brand_id ?? 0);
        if ($brandId > 0 && in_array($brandId, $profile['brand_ids'], true)) {
            $score     = 100;
            $matched[] = trans('admin/category.smart_match_brand', [
                'value' => $profile['brand_names'][$brandId] ?? ($product->brand?->name ?? ('#' . $brandId)),
            ]);
        }

        return [
            'score' => min(100, $score),
            'terms' => array_slice(array_values(array_unique($matched)), 0, 8),
        ];
    }

    /** @return array<int, string> */
    private function keywordList(string|array|null $value): array
    {
        $parts = is_array($value)
            ? $value
            : (preg_split('/[,;|\r\n]+/u', (string) $value) ?: []);

        return array_values(array_unique(array_filter(array_map(
            fn ($term): string => $this->normalize(is_scalar($term) ? (string) $term : ''),
            array_slice($parts, 0, 50),
        ), fn (string $term): bool => $this->usableTerm($term))));
    }

    private function usableTerm(string $term): bool
    {
        if ($term === '' || (preg_match('/^[\x00-\x7F]+$/', $term) && mb_strlen($term) < 2)) {
            return false;
        }

        return ! in_array($term, [
            'the', 'and', 'for', 'with', 'from', 'new', 'sale', 'shop',
            'product', 'products', 'collection', 'collections', 'category', 'categories',
            '商品', '产品', '分类', '商店', '新品',
        ], true);
    }

    private function containsTerm(string $haystack, string $term): bool
    {
        if ($haystack === '' || $term === '') {
            return false;
        }

        return str_contains(' ' . $haystack . ' ', ' ' . $term . ' ')
            || (mb_strlen($term) >= 4 && str_contains($haystack, $term))
            || (preg_match('/[^\x00-\x7F]/', $term) === 1 && str_contains($haystack, $term));
    }

    private function normalize(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /** @param array<string, mixed> $profile */
    private function emptySuggestion(Category $category, array $profile): array
    {
        return [
            'category'      => ['id' => (int) $category->id, 'name' => '#' . $category->id],
            'rule'          => $profile,
            'candidates'    => [],
            'scanned'       => 0,
            'eligible'      => 0,
            'total_matches' => 0,
            'returned'      => 0,
            'truncated'     => false,
        ];
    }
}
