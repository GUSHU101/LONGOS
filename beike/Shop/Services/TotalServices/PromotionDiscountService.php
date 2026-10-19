<?php

namespace Beike\Shop\Services\TotalServices;

use Beike\Exceptions\CartException;
use Beike\Models\Discount;
use Beike\Repositories\DiscountRepo;
use Beike\Shop\Services\CheckoutService;

class PromotionDiscountService
{
    /**
     * Apply the best matching automatic rule for each discount type and one
     * customer-entered code. Keeping type selection here makes the rule result
     * deterministic when administrators create overlapping campaigns.
     */
    public static function getTotal(CheckoutService $checkout): ?array
    {
        $totalService = $checkout->totalService;
        $carts        = $totalService->getCartProducts();
        $subTotal     = (float) $totalService->getSubTotal();
        $totals       = $totalService->totals ?? [];
        $automatic    = [];

        foreach (DiscountRepo::activeAutomatic() as $rule) {
            $amount = self::amountFor($rule, $carts, $subTotal, $totals);
            if ($amount > 0 && (! isset($automatic[$rule->type]) || $amount > $automatic[$rule->type]['amount'])) {
                $automatic[$rule->type] = ['rule' => $rule, 'amount' => $amount];
            }
        }

        $results = array_values($automatic);
        $code    = trim((string) (($checkout->cart->extra ?? [])['discount_code'] ?? ''));
        if ($code !== '' && ($codeRule = DiscountRepo::activeCode($code))) {
            $amount = self::amountFor($codeRule, $carts, $subTotal, $totals);
            if ($amount > 0) {
                $results[] = ['rule' => $codeRule, 'amount' => $amount];
            }
        }

        foreach ($results as $result) {
            $amount    = -min($result['amount'], max(0, $totalService->amount));
            $totalData = [
                'code'          => 'promotion_discount.' . $result['rule']->id,
                'title'         => trans('shop/checkout.discount_title', ['name' => $result['rule']->name]),
                'amount'        => $amount,
                'amount_format' => currency_format($amount),
            ];

            $totalService->amount += $amount;
            $totalService->totals[] = $totalData;
        }

        return $totalData ?? null;
    }

    public static function validateCode(string $code): void
    {
        if ($code !== '' && ! DiscountRepo::activeCode($code)) {
            throw new CartException(trans('shop/checkout.invalid_discount_code'));
        }
    }

    private static function amountFor(Discount $rule, array $carts, float $subTotal, array $totals): float
    {
        $minimumAmount = (float) $rule->minimum_amount;
        if (! is_finite($minimumAmount) || $subTotal < max(0, $minimumAmount)) {
            return 0;
        }

        return match ($rule->type) {
            'product_amount' => self::valueDiscount($rule, self::matchingSubtotal($rule, $carts)),
            'order_amount'   => self::valueDiscount($rule, $subTotal),
            'buy_x_get_y'    => self::buyXGetYDiscount($rule, $carts),
            'free_shipping'  => self::shippingAmount($totals),
            default          => 0,
        };
    }

    private static function valueDiscount(Discount $rule, float $baseAmount): float
    {
        if ($baseAmount <= 0) {
            return 0;
        }

        $value = (float) $rule->value;
        if (! is_finite($value)) {
            return 0;
        }

        // Fixed campaign amounts are authored in the store's USD base
        // currency. Conversion occurs only when this total is displayed.
        $amount = $rule->value_type === 'fixed'
            ? $value
            : $baseAmount * min(100, max(0, $value)) / 100;

        return min($baseAmount, max(0, $amount));
    }

    private static function matchingSubtotal(Discount $rule, array $carts): float
    {
        return collect($carts)
            ->filter(fn (array $cart) => self::matchesProduct($rule->product_ids ?? [], $cart))
            ->sum(fn (array $cart) => (float) ($cart['subtotal'] ?? 0));
    }

    private static function buyXGetYDiscount(Discount $rule, array $carts): float
    {
        $buyItems                = collect($carts)->filter(fn (array $cart) => self::matchesProduct($rule->product_ids ?? [], $cart));
        $buyCount                = $buyItems->sum(fn (array $cart) => max(0, (int) ($cart['quantity'] ?? 0)));
        $hasSeparateGiftProducts = ! empty($rule->gift_product_ids);
        $giftIds                 = $rule->gift_product_ids ?: $rule->product_ids;
        $giftItems               = collect($carts)->filter(fn (array $cart) => self::matchesProduct($giftIds ?? [], $cart));
        $buyQuantity             = max(1, (int) $rule->buy_quantity);
        $getQuantity             = max(1, (int) $rule->get_quantity);
        // When the gift comes from the same product range, a complete set is
        // "buy X + get Y" units. For an explicit gift range, X purchased
        // qualifying units unlock Y units already present in the cart.
        $setSize   = $hasSeparateGiftProducts ? $buyQuantity : $buyQuantity + $getQuantity;
        $sets      = intdiv($buyCount, $setSize);
        $freeCount = $sets * $getQuantity;

        if ($freeCount < 1 || $giftItems->isEmpty()) {
            return 0;
        }

        $units = [];
        foreach ($giftItems as $item) {
            for ($i = 0; $i < max(0, (int) ($item['quantity'] ?? 0)); $i++) {
                $units[] = max(0, (float) ($item['price'] ?? 0));
            }
        }
        sort($units, SORT_NUMERIC);

        return array_sum(array_slice($units, 0, $freeCount));
    }

    private static function shippingAmount(array $totals): float
    {
        foreach ($totals as $total) {
            if (($total['code'] ?? '') === 'shipping') {
                return max(0, (float) ($total['amount'] ?? 0));
            }
        }

        return 0;
    }

    private static function matchesProduct(array $productIds, array $cart): bool
    {
        return empty($productIds) || in_array((int) ($cart['product_id'] ?? 0), array_map('intval', $productIds), true);
    }
}
