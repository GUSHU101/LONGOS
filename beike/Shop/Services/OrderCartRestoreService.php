<?php

namespace Beike\Shop\Services;

use App\Saas\Models\Site;
use App\Services\PlatformPayment\PlatformPaymentSessionService;
use App\Services\PlatformPayment\UnsafeCartRestoreException;
use Beike\Exceptions\CartException;
use Beike\Models\CartProduct;
use Beike\Models\Customer;
use Beike\Models\Order;
use Beike\Models\ProductSku;
use Beike\Repositories\CartRepo;
use Beike\Services\StateMachineService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OrderCartRestoreService
{
    private const MAX_CART_LINES = 500;

    public function __construct(private readonly PlatformPaymentSessionService $paymentSessions) {}

    /** @return array{restored_quantity: int, unavailable_quantity: int} */
    public function restore(Site $site, Order $order, ?Customer $customer): array
    {
        $this->assertOwner($order, $customer);
        if ((string) $order->payment_method_code !== PlatformPaymentSessionService::PAYMENT_CODE
            || (string) $order->status           !== StateMachineService::UNPAID) {
            throw new CartException(trans('shop/checkout.cart_restore_unavailable'));
        }

        $cartIdentity = $customer
            ? 'customer:' . $customer->id
            : 'session:' . get_session_id();
        $cartLock = Cache::lock('cart:restore:' . hash('sha256', $cartIdentity), 30);

        try {
            $cartLock->block(10);
        } catch (LockTimeoutException $exception) {
            throw new CartException(trans('shop/checkout.cart_restore_pending'), 0, $exception);
        }

        try {
            return $this->paymentSessions->restoreCartWhenSafe(
                $site,
                $order,
                fn (): array => DB::transaction(
                    fn (): array => $this->restoreLocked($order, $customer),
                    3,
                ),
            );
        } catch (UnsafeCartRestoreException $exception) {
            throw new CartException(trans('shop/checkout.cart_restore_pending'), 0, $exception);
        } finally {
            $cartLock->release();
        }
    }

    /** @return array{restored_quantity: int, unavailable_quantity: int} */
    private function restoreLocked(Order $sourceOrder, ?Customer $customer): array
    {
        $order = Order::query()->whereKey($sourceOrder->id)->lockForUpdate()->firstOrFail();
        $this->assertOwner($order, $customer);
        if ((string) $order->payment_method_code !== PlatformPaymentSessionService::PAYMENT_CODE
            || (string) $order->status           !== StateMachineService::UNPAID) {
            throw new CartException(trans('shop/checkout.cart_restore_unavailable'));
        }

        $order->loadMissing('orderProducts');
        $orderedBySku = $order->orderProducts
            ->groupBy('product_sku')
            ->map(static fn ($items): int => (int) $items->sum(
                static fn ($item): int => max(0, (int) $item->quantity)
            ))
            ->filter(static fn (int $quantity): bool => $quantity > 0);

        $skus = ProductSku::query()
            ->with('product')
            ->whereIn('sku', $orderedBySku->keys())
            ->lockForUpdate()
            ->get()
            ->keyBy('sku');

        $plans       = [];
        $unavailable = 0;
        foreach ($orderedBySku as $skuCode => $orderedQuantity) {
            /** @var ProductSku|null $sku */
            $sku = $skus->get($skuCode);
            if (! $sku || ! $sku->active || ! $sku->product?->active) {
                $unavailable += $orderedQuantity;

                continue;
            }

            // The order's own reservation is released below. Include exactly
            // that quantity when deciding how much can safely be restored.
            $availableAfterRelease = (int) $sku->quantity + ($order->stock_reserved ? $orderedQuantity : 0);
            $quantity              = min($orderedQuantity, max(0, $availableAfterRelease));
            if ($quantity < 1) {
                $unavailable += $orderedQuantity;

                continue;
            }

            $plans[]     = ['sku' => $sku, 'quantity' => $quantity];
            $unavailable += $orderedQuantity - $quantity;
        }

        if ($plans === []) {
            throw new CartException(trans('shop/checkout.cart_restore_no_products'));
        }

        StateMachineService::getInstance($order)->changeStatus(
            StateMachineService::CANCELLED,
            trans('shop/checkout.cart_restore_cancelled'),
            false,
        );

        CartRepo::createCart($customer);
        $identity = CartProduct::query();
        if ($customer) {
            $identity->where('customer_id', $customer->id);
        } else {
            $identity->where('session_id', get_session_id());
        }

        $cartLineCount = (clone $identity)->lockForUpdate()->get()->count();
        $restored      = 0;
        foreach ($plans as $plan) {
            /** @var ProductSku $sku */
            $sku      = $plan['sku']->refresh();
            $quantity = min((int) $plan['quantity'], max(0, (int) $sku->quantity));
            if ($quantity < 1) {
                $unavailable += (int) $plan['quantity'];

                continue;
            }

            $existing = (clone $identity)
                ->where('product_id', $sku->product_id)
                ->where('product_sku', $sku->sku)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $target   = min((int) $existing->quantity + $quantity, (int) $sku->quantity);
                $increase = max(0, $target - (int) $existing->quantity);
                if ($increase > 0) {
                    $existing->forceFill(['quantity' => $target, 'selected' => true])->save();
                    $restored += $increase;
                }
                $unavailable += $quantity - $increase;

                continue;
            }

            if ($cartLineCount >= self::MAX_CART_LINES) {
                $unavailable += $quantity;

                continue;
            }

            CartProduct::query()->create([
                'customer_id' => $customer?->id ?? 0,
                'session_id'  => get_session_id(),
                'product_id'  => $sku->product_id,
                'product_sku' => $sku->sku,
                'quantity'    => $quantity,
                'selected'    => true,
            ]);
            $cartLineCount++;
            $restored += $quantity;
        }

        if ($restored < 1) {
            throw new CartException(trans('shop/checkout.cart_restore_no_products'));
        }

        CartService::resetRuntimeCache();

        return [
            'restored_quantity'    => $restored,
            'unavailable_quantity' => $unavailable,
        ];
    }

    private function assertOwner(Order $order, ?Customer $customer): void
    {
        $customerId = (int) ($customer?->id ?? 0);
        if ((int) $order->customer_id !== $customerId) {
            throw new CartException(trans('shop/order.invalid_order'));
        }
    }
}
