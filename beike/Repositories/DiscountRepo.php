<?php

namespace Beike\Repositories;

use Beike\Models\Discount;
use Illuminate\Database\Eloquent\Collection;

class DiscountRepo
{
    public static function list(): Collection
    {
        return Discount::query()->orderByDesc('id')->get();
    }

    public static function find(int $id): Discount
    {
        return Discount::query()->findOrFail($id);
    }

    public static function activeAutomatic(): Collection
    {
        return Discount::query()->active()->where('mode', 'automatic')->orderBy('id')->get();
    }

    public static function activeCode(string $code): ?Discount
    {
        return Discount::query()->active()
            ->where('mode', 'code')
            ->where('code', strtoupper(trim($code)))
            ->first();
    }
}
