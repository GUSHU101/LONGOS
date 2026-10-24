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
                // SKU media is loaded only after a shopper changes a variant.
                // Returning the uploaded URL avoids synchronously generating
                // several derivatives for every SKU during the initial page.
                $origin = image_origin($image);

                return array_fill_keys(['small', 'preview', 'large', 'popup', 'thumb'], $origin);
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
