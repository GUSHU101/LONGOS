<?php

namespace Tests\Feature;

use Beike\Models\CartProduct;
use Beike\Models\Product;
use Beike\Models\ProductSku;
use Beike\Shop\Services\CheckoutService;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class CheckoutShippingWeightTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config(['bk.system.base.weight' => 'kg']);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_cart_weight_uses_sku_override_product_fallback_and_excludes_virtual_products(): void
    {
        $checkout                   = (new ReflectionClass(CheckoutService::class))->newInstanceWithoutConstructor();
        $checkout->selectedProducts = collect([
            $this->cartProduct(2, 2, 'kg', 3, true),
            $this->cartProduct(2, 500, 'g', 0, true),
            $this->cartProduct(1, 50, 'kg', 0, false),
        ]);

        self::assertSame(7.0, $checkout->getCartWeight());
    }

    private function cartProduct(int $quantity, float $productWeight, string $weightClass, float $skuWeight, bool $shipping): CartProduct
    {
        $product = new Product([
            'weight'       => $productWeight,
            'weight_class' => $weightClass,
            'shipping'     => $shipping,
        ]);
        $sku = new ProductSku(['weight' => $skuWeight]);
        $sku->setRelation('product', $product);

        $cartProduct           = new CartProduct;
        $cartProduct->quantity = $quantity;
        $cartProduct->setRelation('product', $product);
        $cartProduct->setRelation('sku', $sku);

        return $cartProduct;
    }
}
