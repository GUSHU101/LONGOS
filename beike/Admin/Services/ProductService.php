<?php

namespace Beike\Admin\Services;

use Beike\Models\Product;
use Beike\Models\ProductSku;
use Beike\Services\RichTextSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductService
{
    public function create(array $data): Product
    {
        $product = new Product;

        return $this->createOrUpdate($product, $data);
    }

    public function update(Product $product, array $data): Product
    {
        return $this->createOrUpdate($product, $data);
    }

    protected function createOrUpdate(Product $product, array $data): Product
    {
        $isUpdating = $product->id > 0;

        return DB::transaction(function () use ($product, $data, $isUpdating): Product {
            hook_action('admin.service.product.create_or_update.before', ['product' => $product, 'data' => $data]);

            $data = hook_filter('admin.service.product.create_or_update.before', ['product' => $product, 'data' => $data]);
            $data = $this->normalizeData($data['data'] ?? []);
            $this->assertSkusAvailable($product, $data['skus']);

            $data['brand_id']  = (int) ($data['brand_id'] ?? 0);
            $data['position']  = (int) ($data['position'] ?? 0);
            $data['weight']    = (float) ($data['weight'] ?? 0);
            $data['shipping']  = (bool) ($data['shipping'] ?? 1);
            $data['video']     = $data['video'] ?? '';
            $product->fill($data);
            $product->updated_at = now();
            $product->save();

            if ($isUpdating) {
                $product->descriptions()->delete();
                $product->attributes()->delete();
            } else {
                $data['images']  = static::moveImagesAndGetNewPaths('product-' . $product->id, $data['images'] ?? []);
                $product->images = $data['images'];
                $product->save();
            }

            $descriptions = [];
            foreach ($data['descriptions'] as $locale => $description) {
                $description['locale']  = $locale;
                $description['content'] = RichTextSanitizer::sanitize($description['content'] ?? '');

                $descriptions[] = $description;
            }
            $product->descriptions()->createMany($descriptions);

            $product->attributes()->createMany($data['attributes'] ?? []);

            $this->syncSkus($product, $data['skus'], $isUpdating);

            $product->categories()->sync($data['categories'] ?? []);
            $product->relations()->sync($data['relations'] ?? []);

            $product->refresh();
            (new ProductCategoryAssignmentService)->autoAssign($product);
            hook_action('admin.service.product.create_or_update.after', ['product' => $product, 'data' => $data]);

            return $product;
        });
    }

    /**
     * Normalize optional fields from both the admin form and import plugins.
     */
    private function normalizeData(array $data): array
    {
        $variables = $data['variables'] ?? [];
        if (is_string($variables)) {
            $variables = json_decode($variables, true);
        } elseif (is_object($variables)) {
            $variables = json_decode(json_encode($variables, JSON_THROW_ON_ERROR), true);
        }
        $data['variables'] = is_array($variables) ? $variables : [];

        $data['descriptions'] = array_filter(
            (array) ($data['descriptions'] ?? []),
            static fn ($description): bool => is_array($description),
        );
        $data['descriptions'] = array_map(static function (array $description): array {
            return array_merge([
                'name'             => '',
                'content'          => '',
                'meta_title'       => '',
                'meta_description' => '',
                'meta_keywords'    => '',
            ], $description);
        }, $data['descriptions']);

        $data['skus'] = array_values(array_map(function ($sku, int $index): array {
            $sku = is_array($sku) ? $sku : [];

            $sku['sku']          = trim((string) ($sku['sku'] ?? ''));
            $sku['model']        = (string) ($sku['model'] ?? '');
            $sku['price']        = (float) ($sku['price'] ?? 0);
            $sku['origin_price'] = (float) ($sku['origin_price'] ?? 0);
            $sku['cost_price']   = (float) ($sku['cost_price'] ?? 0);
            $sku['weight']       = (float) ($sku['weight'] ?? 0);
            $sku['quantity']     = (int) ($sku['quantity'] ?? 0);
            $sku['position']     = $index;
            $sku['is_default']   = $index === 0;
            $sku['active']       = $index === 0 || (bool) ($sku['active'] ?? true);
            $sku['images']       = $this->normalizeArrayValue($sku['images'] ?? []);
            $sku['variants']     = $this->normalizeArrayValue($sku['variants'] ?? []);

            return $sku;
        }, (array) ($data['skus'] ?? []), array_keys((array) ($data['skus'] ?? []))));

        return $data;
    }

    private function normalizeArrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function assertSkusAvailable(Product $product, array $skus): void
    {
        $codes = collect($skus)->pluck('sku')->filter()->values();
        if ($codes->count() !== count($skus) || $codes->isEmpty()) {
            throw new \Exception(trans('validation.required', ['attribute' => 'SKU']));
        }

        if ($codes->count() !== $codes->map(static fn ($sku): string => mb_strtolower($sku))->unique()->count()) {
            throw new \Exception(trans('validation.distinct', ['attribute' => 'SKU']));
        }

        $conflict = ProductSku::query()
            ->whereIn('sku', $codes)
            ->when($product->exists, fn ($query) => $query->where('product_id', '!=', $product->id))
            ->exists();

        if ($conflict) {
            throw new \Exception(trans('validation.unique', ['attribute' => 'SKU']));
        }
    }

    /**
     * Preserve SKU primary keys while editing so carts and integrations do not
     * lose their product variant identity on every manual save.
     */
    private function syncSkus(Product $product, array $skus, bool $isUpdating): void
    {
        $existing = $isUpdating
            ? $product->skus()->lockForUpdate()->get()
            : collect();
        $existingById   = $existing->keyBy('id');
        $existingByCode = $existing->keyBy('sku');

        // Free the unique SKU values first. This also makes swapping two SKU
        // codes in one edit safe without deleting and recreating the records.
        foreach ($existing as $current) {
            $current->forceFill([
                'sku' => '__longos_edit_' . $current->id . '_' . Str::lower(Str::random(12)),
            ])->save();
        }

        $retained = [];
        foreach ($skus as $sku) {
            $submittedId = (int) ($sku['id'] ?? 0);
            $current     = $submittedId > 0 ? $existingById->get($submittedId) : null;
            $current   ??= $existingByCode->get($sku['sku']);
            $current   ??= new ProductSku;

            unset($sku['id'], $sku['product_sku_id'], $sku['sku_error']);
            $current->fill($sku);
            $current->product_id = $product->id;
            $current->save();
            $retained[] = $current->id;
        }

        if ($isUpdating) {
            $product->skus()->whereNotIn('id', $retained)->delete();
        }
    }

    /**
     * $images图片移动到子目录‘product-{$product->id}’并返回新的图片路径
     * @param mixed $dirName
     * @param mixed $images
     */
    public static function moveImagesAndGetNewPaths($dirName, $images)
    {
        // 定义原始路径前缀和目标子目录
        $oldPrefix = tenant_catalog_prefix() . '/products/';
        $newSubDir = tenant_catalog_prefix() . '/products/' . $dirName . '/';

        // 获取存储路径
        $publicPath = public_path();

        // 遍历图片数组，移动符合条件的图片并更新路径
        foreach ($images as &$image) {
            // 条件为$image在$oldPrefix目录下，但不是在其子目录下
            if (str_starts_with($image, $oldPrefix)) {
                // 获取$oldPrefix之后的部分路径
                $relativePath = substr($image, strlen($oldPrefix));
                // 检查相对路径中是否包含斜杠（如果包含斜杠，表示在子目录中）
                if (strpos($relativePath, '/') === false) {
                    $oldImagePath = $publicPath . '/' . $image;
                    $newImagePath = $publicPath . '/' . $newSubDir . $relativePath;

                    // 确保目标目录存在
                    $newDir = dirname($newImagePath);
                    if (! is_dir($newDir)) {
                        mkdir($newDir, 0755, true);
                    }

                    // 移动图片文件
                    if (file_exists($oldImagePath)) {
                        rename($oldImagePath, $newImagePath);
                    }

                    // 更新图片路径
                    $image = $newSubDir . $relativePath;
                }
            }
        }
        unset($image); // 释放引用

        return $images;
    }
}
