<?php

namespace Beike\Models;

use Illuminate\Database\Eloquent\Builder;

class Discount extends Base
{
    protected $fillable = [
        'name', 'mode', 'code', 'type', 'value_type', 'value', 'minimum_amount',
        'minimum_quantity', 'buy_quantity', 'get_quantity', 'product_ids', 'gift_product_ids', 'status',
        'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'product_ids'      => 'array',
            'gift_product_ids' => 'array',
            'status'           => 'boolean',
            'value'            => 'float',
            'minimum_amount'   => 'float',
            'minimum_quantity' => 'integer',
            'starts_at'        => 'datetime',
            'ends_at'          => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('status', true)
            ->where(function (Builder $query) use ($now) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $query) use ($now) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });
    }
}
