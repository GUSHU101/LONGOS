<?php

namespace Beike\Shop\Http\Controllers;

use Beike\Models\CartProduct;
use Beike\Models\DashboardEvent;
use Beike\Models\ProductSku;
use Beike\Shop\Http\Requests\CartRequest;
use Beike\Shop\Services\CartService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CartController extends Controller
{
    /**
     * @return View
     */
    public function index(): View
    {
        $data = CartService::reloadData();

        $data = hook_filter('cart.index.data', $data);

        return view('cart/cart', $data);
    }

    /**
     * 选中购物车商品
     *
     * POST /carts/select {cart_ids:[1, 2]}
     * @param Request $request
     * @return JsonResponse
     */
    public function select(Request $request): JsonResponse
    {
        try {
            $cartIds  = $request->get('cart_ids');
            $customer = current_customer();
            $this->withCartMutationLock($customer, fn () => CartService::select($customer, $cartIds));

            $data = CartService::reloadData();

            $data = hook_filter('cart.select.data', $data);

            return json_success(trans('common.updated_success'), $data);
        } catch (LockTimeoutException) {
            return json_fail(trans('shop/carts.request_in_progress'));
        } catch (\Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    /**
     * 不选中购物车商品
     *
     * POST /carts/unselect {cart_ids:[1, 2]}
     * @param Request $request
     * @return JsonResponse
     */
    public function unselect(Request $request): JsonResponse
    {
        try {
            $cartIds  = $request->get('cart_ids');
            $customer = current_customer();
            $this->withCartMutationLock($customer, fn () => CartService::unselect($customer, $cartIds));

            $data = CartService::reloadData();

            $data = hook_filter('cart.select.data', $data);

            return json_success(trans('common.updated_success'), $data);
        } catch (LockTimeoutException) {
            return json_fail(trans('shop/carts.request_in_progress'));
        } catch (\Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    /**
     * POST /carts {sku_id:1, quantity: 2}
     * 创建购物车
     *
     * @param CartRequest $request
     * @return mixed
     * @throws \Exception
     */
    public function store(CartRequest $request)
    {
        try {
            $skuId      = $request->sku_id;
            $quantity   = $request->quantity       ?? 1;
            $buyNow     = (bool) $request->buy_now ?? false;
            $customer   = current_customer();
            $requestKey = trim((string) $request->input('idempotency_key', ''));

            $sku = ProductSku::query()
                ->whereRelation('product', 'active', '=', true)
                ->findOrFail($skuId);

            [$cart, $mutated] = $this->addCartOnce($sku, $quantity, $buyNow, $customer, $requestKey);

            if ($mutated) {
                DashboardEvent::recordCartAdded($sku, $quantity, $customer);
            }

            // A very late replay may arrive after the shopper removed the
            // original line. Its cached array is a safe receipt snapshot;
            // model-oriented plugin hooks must not receive it.
            if ($mutated || $cart instanceof CartProduct) {
                $cart = hook_filter('cart.store.data', $cart);
            }

            return json_success(trans('shop/carts.added_to_cart'), $cart);
        } catch (LockTimeoutException) {
            return json_fail(trans('shop/carts.request_in_progress'));
        } catch (\Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    /**
     * Serializes mutations for one shopper cart and replays the first result
     * when a browser retries the same request after a slow network response.
     */
    private function addCartOnce($sku, int $quantity, bool $buyNow, $customer, string $requestKey): array
    {
        $identity = isset($customer->id) && (int) $customer->id > 0
            ? 'customer:' . (int) $customer->id
            : 'session:' . get_session_id();
        $mutationKey = $this->cartMutationLockKey($customer);
        $receiptKey  = $requestKey === ''
            ? ''
            : 'cart:add:receipt:' . hash('sha256', $identity . '|' . $requestKey);
        $fingerprint = hash('sha256', implode('|', [(int) $sku->id, $quantity, $buyNow ? '1' : '0']));

        return Cache::lock($mutationKey, 60)->block(5, function () use ($sku, $quantity, $buyNow, $customer, $receiptKey, $fingerprint) {
            if ($receiptKey !== '' && is_array($receipt = Cache::get($receiptKey))) {
                if (! hash_equals((string) ($receipt['fingerprint'] ?? ''), $fingerprint)) {
                    throw new \RuntimeException(trans('shop/carts.request_conflict'));
                }
                $cart = $this->ownedCart($customer, (int) ($receipt['cart_id'] ?? 0));

                return [$cart ?: ($receipt['cart'] ?? []), false];
            }

            $cart = DB::transaction(function () use ($sku, $quantity, $buyNow, $customer) {
                $cart = CartService::add($sku, $quantity, $customer);
                if ($buyNow) {
                    if ((int) $cart->quantity !== $quantity) {
                        CartService::updateQuantity($customer, $cart->id, $quantity);
                        $cart->refresh();
                    }
                    CartService::select($customer, [$cart->id], true);
                    $cart->selected = true;
                }

                return $cart;
            }, 3);

            if ($receiptKey !== '') {
                try {
                    Cache::put($receiptKey, [
                        'fingerprint' => $fingerprint,
                        'cart_id'     => (int) $cart->id,
                        'cart'        => $cart->toArray(),
                    ], now()->addMinutes(15));
                } catch (\Throwable $exception) {
                    // The mutation is committed. Returning success is safer
                    // than inviting a retry that could add it a second time.
                    Log::warning('Cart idempotency receipt could not be stored.', [
                        'exception' => $exception::class,
                    ]);
                }
            }

            return [$cart, true];
        });
    }

    private function ownedCart($customer, int $cartId): ?CartProduct
    {
        if ($cartId < 1) {
            return null;
        }

        $builder = CartProduct::query()->whereKey($cartId);
        if (isset($customer->id) && (int) $customer->id > 0) {
            $builder->where('customer_id', (int) $customer->id);
        } else {
            $builder->where('session_id', get_session_id());
        }

        return $builder->first();
    }

    private function cartMutationLockKey($customer): string
    {
        return CartService::mutationLockKey($customer);
    }

    private function withCartMutationLock($customer, callable $operation): mixed
    {
        return Cache::lock($this->cartMutationLockKey($customer), 60)->block(5, $operation);
    }

    /**
     * PUT /carts/{cart_id} {sku_id:1, quantity: 2}
     * @param CartRequest $request
     * @param             $cartId
     * @return JsonResponse
     */
    public function update(CartRequest $request, $cartId): JsonResponse
    {
        try {
            $customer = current_customer();
            $quantity = (int) $request->get('quantity');
            $this->withCartMutationLock(
                $customer,
                fn () => CartService::updateQuantity($customer, $cartId, $quantity),
            );

            $data = CartService::reloadData();

            $data = hook_filter('cart.update.data', $data);

            return json_success(trans('common.updated_success'), $data);
        } catch (LockTimeoutException) {
            return json_fail(trans('shop/carts.request_in_progress'));
        } catch (\Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    /**
     * DELETE /carts/{cart_id}
     * @param Request $request
     * @param         $cartId
     * @return JsonResponse
     */
    public function destroy(Request $request, $cartId): JsonResponse
    {
        try {
            $customer = current_customer();
            $this->withCartMutationLock($customer, fn () => CartService::delete($customer, $cartId));

            $data = CartService::reloadData();

            $data = hook_filter('cart.destroy.data', $data);

            return json_success(trans('common.deleted_success'), $data);
        } catch (LockTimeoutException) {
            return json_fail(trans('shop/carts.request_in_progress'));
        } catch (\Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    /**
     * 右上角购物车
     * @return mixed
     */
    public function miniCart()
    {
        $carts      = CartService::list(current_customer());
        $reloadData = CartService::reloadData($carts);

        $data['html']         = view('cart/mini', $reloadData)->render();
        $data['quantity']     = $reloadData['quantity'];
        $data['quantity_all'] = $reloadData['quantity_all'];
        $data['notices']      = $reloadData['notices'] ?? [];

        $data = hook_filter('cart.mini_cart.data', $data);

        return json_success(trans('common.success'), $data);
    }
}
