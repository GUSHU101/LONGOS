<?php

/**
 * OrderTotal.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-28 11:26:47
 * @modified   2022-07-28 11:26:47
 */

namespace Beike\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderTotal extends Base
{
    protected $fillable = [
        'order_id', 'code', 'value', 'title', 'reference',
    ];

    protected $appends = ['value_format'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function getValueFormatAttribute()
    {
        if ($this->relationLoaded('order') && $this->order) {
            return currency_format($this->value, $this->order->currency_code, $this->order->currency_value);
        }

        return currency_format($this->value);
    }
}
