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
            // Product pages never synchronously transform the gallery. The
            // response uses ready derivatives and queues missing ones while
            // keeping the uploaded source as a first-visit fallback.
            'images'           => array_map(static fn ($image): array => self::imagePayload($image), $images),
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
                        // Variant media is only needed after interaction, so
                        // consume prepared derivatives without queueing every
                        // possible option during the initial request.
                        'image'         => $image ? image_origin($image) : '',
                        'gallery_image' => $image ? self::imagePayload($image, false) : null,
                    ];
                }, $item['values']),
            ];
        }, $variables);
    }

    private static function imagePayload(mixed $image, bool $schedule = true): array
    {
        $origin = image_origin($image);

        return [
            'small'   => image_cached_resize($image, 480, calculate_height_by_ratio(480), $schedule),
            'preview' => image_cached_resize($image, 800, calculate_height_by_ratio(800), $schedule),
            'large'   => image_cached_resize($image, 1200, calculate_height_by_ratio(1200), $schedule),
            'popup'   => $origin,
            'thumb'   => image_cached_resize($image, 160, calculate_height_by_ratio(160), $schedule),
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
