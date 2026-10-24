<?php

/**
 * ProductDetail.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-06-23 11:33:06
 * @modified   2022-06-23 11:33:06
 */

namespace Beike\Shop\Http\Resources;

use Beike\Services\RichTextSanitizer;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductDetail extends JsonResource
{
    /**
     * Generating derivatives is synchronous in the compatibility image
     * service. Bound cold-cache work to the five images actually rendered in
     * the desktop gallery; the lightbox can use uploaded originals for the
     * remaining images without delaying or exhausting the product request.
     */
    private const DERIVATIVE_IMAGE_LIMIT = 5;

    /**
     * @throws \Exception
     */
    public function toArray($request): array
    {
        $images = array_values(array_filter((array) $this->images, function ($image) {
            $isYouTube = str_contains($image, 'youtube.com/watch') || str_contains($image, 'youtu.be/');

            return ! str_ends_with($image, '.mp4') && ! $isYouTube;
        }));

        $data = [
            'id'               => $this->id,
            'name'             => $this->description->name             ?? '',
            'description'      => RichTextSanitizer::sanitize($this->description->content ?? ''),
            'meta_title'       => $this->description->meta_title       ?? '',
            'meta_keywords'    => $this->description->meta_keywords    ?? '',
            'meta_description' => $this->description->meta_description ?? '',
            'brand_id'         => $this->brand->id                     ?? 0,
            'brand_name'       => $this->brand->name                   ?? '',
            'video'            => $this->video                         ?? '',
            'weight'           => $this->weight                        ?? '',
            'weight_class'     => $this->weight_class                  ?? '',
            'images'           => array_map(
                static fn ($image, int $index): array => self::imagePayload($image, $index < self::DERIVATIVE_IMAGE_LIMIT),
                $images,
                array_keys($images),
            ),
            'attributes'       => $this->formatAttributes(),
            'variables'        => $this->decodeVariables($this->variables),
            'skus'             => SkuDetail::collection($this->skus)->jsonSerialize(),
            'in_wishlist'      => $this->inCurrentWishlist->id ?? 0,
            'active'           => (bool) $this->active,
        ];

        return hook_filter('resource.product.detail', $data);
    }

    /**
     * 处理多规格商品数据
     *
     * @param $variables
     * @return array|array[]
     * @throws \Exception
     */
    private function decodeVariables($variables): array
    {
        $lang = locale();
        if (empty($variables)) {
            return [];
        }

        return array_map(function ($item) use ($lang) {
            return [
                'name'   => $item['name'][$lang] ?? '',
                'values' => array_map(function ($item) use ($lang) {
                    $image = $item['image'] ?? '';

                    return [
                        'name'          => $item['name'][$lang] ?? '',
                        // Variant media is only needed after interaction. Use
                        // the uploaded URL for every responsive slot so a
                        // cold product request does not resize every option.
                        'image'         => $image ? image_origin($image) : '',
                        'gallery_image' => $image ? self::imagePayload($image, false) : null,
                    ];
                }, $item['values']),
            ];
        }, $variables);
    }

    private static function imagePayload(mixed $image, bool $withDerivatives): array
    {
        $origin = image_origin($image);
        if (! $withDerivatives) {
            return array_fill_keys(['small', 'preview', 'large', 'popup', 'thumb'], $origin);
        }

        $small = image_resize($image, 480, calculate_height_by_ratio(480));

        return [
            'small'   => $small,
            'preview' => image_resize($image, 800, calculate_height_by_ratio(800)),
            'large'   => image_resize($image, 1200, calculate_height_by_ratio(1200)),
            'popup'   => $origin,
            // The current gallery has no thumbnail strip. Reuse the 480px
            // candidate instead of creating a fourth derivative per image.
            'thumb' => $small,
        ];
    }

    /**
     * 格式化属性数据
     *
     * @return array
     */
    private function formatAttributes(): array
    {
        return $this->attributes
            ->sortBy(fn ($a) => $a->attribute->attributeGroup->sort_order) // 对属性组排序
            ->groupBy(fn ($item) => $item->attribute->attribute_group_id) // 按属性组分组
            ->map(function ($items) {
                $first = $items->first();

                return [
                    'attribute_group_name' => $first->attribute->attributeGroup->description->name,
                    'attributes'           => $items
                        ->sortBy(fn ($a) => $a->attribute->sort_order) // 对组内属性排序
                        ->map(fn ($a) => [
                            'attribute'       => $a->attribute->description->name,
                            'attribute_value' => $a->attributeValue->description->name,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->toArray();
    }
}
