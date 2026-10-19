<?php

namespace Beike\Admin\Services;

use Beike\Models\Discount;
use Beike\Repositories\DiscountRepo;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class DiscountService
{
    public static function create(array $data): Discount
    {
        return Discount::query()->create(self::params($data));
    }

    public static function update(int $id, array $data): Discount
    {
        $discount = DiscountRepo::find($id);
        $discount->update(self::params($data, $discount));

        return $discount->refresh();
    }

    private static function params(array $data, ?Discount $current = null): array
    {
        $mode = $data['mode'] ?? 'automatic';
        $type = $data['type'] ?? 'product_amount';
        $code = $mode === 'code' ? strtoupper(trim((string) ($data['code'] ?? ''))) : null;

        if ($mode === 'code' && $code === '') {
            throw ValidationException::withMessages(['code' => ['折扣码不能为空。']]);
        }

        if ($code && Discount::query()->where('code', $code)->when($current, fn ($query) => $query->whereKeyNot($current->id))->exists()) {
            throw ValidationException::withMessages(['code' => ['该折扣码已被使用。']]);
        }

        return [
            'name'             => trim((string) $data['name']),
            'mode'             => $mode,
            'code'             => $code,
            'type'             => $type,
            'value_type'       => $data['value_type'] ?? 'percentage',
            'value'            => in_array($type, ['product_amount', 'order_amount'], true) ? (float) ($data['value'] ?? 0) : 0,
            'minimum_amount'   => (float) ($data['minimum_amount'] ?? 0),
            'buy_quantity'     => max(1, (int) ($data['buy_quantity'] ?? 1)),
            'get_quantity'     => max(1, (int) ($data['get_quantity'] ?? 1)),
            'product_ids'      => self::productIds($data['product_ids'] ?? []),
            'gift_product_ids' => self::productIds($data['gift_product_ids'] ?? []),
            'status'           => (bool) ($data['status'] ?? false),
            'starts_at'        => self::date($data['starts_at'] ?? null),
            'ends_at'          => self::date($data['ends_at'] ?? null),
        ];
    }

    private static function productIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', trim($value));
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value))));
    }

    private static function date(mixed $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }
}
