<?php

namespace Tests\Unit;

use Beike\Models\Discount;
use Beike\Shop\Services\TotalServices\PromotionDiscountService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class PromotionDiscountQuantityTest extends TestCase
{
    public function test_product_discount_counts_only_qualifying_products(): void
    {
        $rule = $this->discount([
            'type'           => 'product_amount', 'value_type' => 'percentage', 'value' => 10,
            'minimum_amount' => 0, 'minimum_quantity' => 3, 'product_ids' => [10],
        ]);
        $carts = [
            ['product_id' => 10, 'quantity' => 2, 'subtotal' => 40, 'price' => 20],
            ['product_id' => 20, 'quantity' => 4, 'subtotal' => 80, 'price' => 20],
        ];

        self::assertSame(0.0, $this->amountFor($rule, $carts, 120));
        $carts[0]['quantity'] = 3;
        self::assertSame(4.0, $this->amountFor($rule, $carts, 120));
    }

    public function test_order_discount_counts_all_cart_items_and_combines_thresholds(): void
    {
        $rule = $this->discount([
            'type'           => 'order_amount', 'value_type' => 'fixed', 'value' => 15,
            'minimum_amount' => 100, 'minimum_quantity' => 5,
        ]);
        $carts = [
            ['product_id' => 10, 'quantity' => 2, 'subtotal' => 60, 'price' => 30],
            ['product_id' => 20, 'quantity' => 3, 'subtotal' => 60, 'price' => 20],
        ];

        self::assertSame(15.0, $this->amountFor($rule, $carts, 120));
        self::assertSame(0.0, $this->amountFor($rule, $carts, 90));
    }

    public function test_discount_service_uses_localized_validation_messages(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__, 2) . '/beike/Admin/Services/DiscountService.php');

        self::assertStringContainsString("trans('admin/discount.code_required')", $service);
        self::assertStringContainsString("trans('admin/discount.code_taken')", $service);
        self::assertStringNotContainsString('折扣码不能为空', $service);
        self::assertStringNotContainsString('该折扣码已被使用', $service);
    }

    private function amountFor(Discount $rule, array $carts, float $subTotal): float
    {
        $method = new ReflectionMethod(PromotionDiscountService::class, 'amountFor');

        return $method->invoke(null, $rule, $carts, $subTotal, []);
    }

    private function discount(array $attributes): Discount
    {
        /** @var Discount $discount */
        $discount = (new ReflectionClass(Discount::class))->newInstanceWithoutConstructor();
        $discount->setRawAttributes($attributes);

        return $discount;
    }
}
