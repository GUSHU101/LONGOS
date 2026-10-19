<?php

/**
 * OrderProductList.php
 *
 * @copyright  2023 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2023-08-14 18:41:19
 * @modified   2023-08-14 18:41:19
 */

namespace Beike\Shop\Http\Resources\Account;

use Beike\Repositories\RmaRepo;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderProductSimple extends JsonResource
{
    public function toArray($request): array
    {
        $order  = $this->resource->relationLoaded('order') ? $this->resource->order : null;
        $format = fn ($amount): string => $order
            ? currency_format($amount, $order->currency_code, $order->currency_value)
            : currency_format($amount);

        $data = [
            'id'            => $this->id,
            'product_id'    => $this->product_id,
            'name'          => $this->name,
            'sku'           => $this->product_sku,
            'quantity'      => $this->quantity,
            'rma_quantity'  => (int) RmaRepo::getRmaQuantity($this->id),
            'price'         => $format($this->price),
            'total'         => $this->price * $this->quantity,
            'total_format'  => $format($this->price * $this->quantity),
            'image'         => image_resize($this->image),
        ];

        return $data;
    }
}
