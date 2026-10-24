<?php

/**
 * SkuDetail.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-20 11:33:06
 * @modified   2022-07-20 11:33:06
 */

namespace Beike\Shop\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SkuDetail extends JsonResource
{
    public function toArray($request): array
    {
        $result = [
            'id'                  => $this->id,
            'product_id'          => $this->product_id,
            'variants'            => $this->variants ?: [],
            'position'            => $this->position,
            'active'              => $this->active,
            'images'              => array_map(function ($image) {
                $origin = image_origin($image);

                return [
                    // SKU media is prepared when it is also present in the
                    // base gallery. Do not enqueue hundreds of unused variant
                    // images during initial render.
                    'small'   => image_cached_resize($image, 480, calculate_height_by_ratio(480), false),
                    'preview' => image_cached_resize($image, 800, calculate_height_by_ratio(800), false),
                    'large'   => image_cached_resize($image, 1200, calculate_height_by_ratio(1200), false),
                    'popup'   => $origin,
                    'thumb'   => image_cached_resize($image, 160, calculate_height_by_ratio(160), false),
                ];
            }, $this->images ?? []),
            'model'                  => $this->model,
            'sku'                    => $this->sku,
            'price'                  => $this->price,
            'price_converted'        => (float) currency_format($this->price, '', '', false),
            'price_format'           => currency_format($this->price),
            'origin_price'           => $this->origin_price,
            'origin_price_converted' => (float) currency_format($this->origin_price, '', '', false),
            'origin_price_format'    => currency_format($this->origin_price),
            'currency_code'          => current_currency_code(),
            'quantity'               => $this->quantity,
            'weight'                 => $this->weight ?? '',
            'is_default'             => $this->is_default,
        ];

        return hook_filter('resource.sku.detail', $result);
    }
}
