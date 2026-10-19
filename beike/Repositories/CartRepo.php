<?php

/**
 * CartRepo.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-04 17:14:14
 * @modified   2022-07-04 17:14:14
 */

namespace Beike\Repositories;

use Beike\Models\Cart;
use Beike\Models\CartProduct;
use Beike\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class CartRepo
{
    /**
     * 创建购物车
     *
     * @param $customer
     * @return Cart
     */
    public static function createCart($customer)
    {
        if (is_numeric($customer)) {
            $customer = Customer::query()->find($customer);
        }
        $customerId = $customer->id ?? 0;
        $sessionId  = get_session_id();
        if ($customerId) {
            $cart = Cart::query()->where('customer_id', $customerId)->first();
        } else {
            $cart = Cart::query()->where('session_id', $sessionId)->first();
        }
        $defaultAddressId = $customer->address_id ?? 0;
        if ($defaultAddressId && (int) (AddressRepo::find($defaultAddressId)?->customer_id ?? 0) !== (int) $customerId) {
            $defaultAddressId = 0;
        }
        if (empty($defaultAddressId) && $customer) {
            $firstAddress     = AddressRepo::listByCustomer($customer)->first();
            $defaultAddressId = $firstAddress->id ?? 0;
        }

        if (empty($cart)) {
            $paymentMethod      = PluginRepo::getPaymentMethods()->first();
            $cart               = Cart::query()->create([
                'customer_id'          => $customerId,
                'session_id'           => $sessionId,
                'shipping_address_id'  => $defaultAddressId,
                // Quote suffixes are configured by each shipping plugin. Do
                // not invent the legacy `.0` suffix; CheckoutService selects
                // the first quote valid for the entered delivery address.
                'shipping_method_code' => '',
                'payment_address_id'   => $defaultAddressId,
                'payment_method_code'  => $paymentMethod->code ?? '',
            ]);
        } else {
            $shippingAddress = $cart->shipping_address_id ? AddressRepo::find($cart->shipping_address_id) : null;
            if (! $shippingAddress || (int) $shippingAddress->customer_id !== (int) $customerId) {
                $cart->shipping_address_id = $defaultAddressId;
            }
            $paymentAddress = $cart->payment_address_id ? AddressRepo::find($cart->payment_address_id) : null;
            if (! $paymentAddress || (int) $paymentAddress->customer_id !== (int) $customerId) {
                $cart->payment_address_id = $defaultAddressId;
            }
            $cart->save();
        }
        $cart->loadMissing(['shippingAddress', 'paymentAddress']);

        return $cart;
    }

    /**
     * 清空购物车以及购物车已选中商品
     *
     * @param $customer
     */
    public static function clearSelectedCartProducts($customer)
    {
        if (is_numeric($customer)) {
            $customer = Customer::query()->find($customer);
        }
        $customerId = $customer->id ?? 0;
        if ($customer) {
            Cart::query()->where('customer_id', $customerId)->delete();
        } else {
            Cart::query()->where('session_id', get_session_id())->delete();
        }
        self::selectedCartProductsBuilder($customerId)->delete();
    }

    public static function shippingRequired($customerId)
    {
        $cartList           = self::selectedCartProducts($customerId);
        foreach ($cartList as $item) {
            if ($item->product?->shipping) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取已选中购物车商品列表
     *
     * @param $customerId
     * @return Builder[]|Collection
     */
    public static function selectedCartProducts($customerId)
    {
        $cartProducts = self::selectedCartProductsBuilder($customerId)->get();

        $cartProducts = hook_filter('cart.repo.selected.products', $cartProducts);

        return $cartProducts;
    }

    /**
     * 已选中购物车商品 builder
     *
     * @param $customerId
     * @return Builder
     */
    public static function selectedCartProductsBuilder($customerId): Builder
    {
        return self::allCartProductsBuilder($customerId)->where('selected', true);
    }

    /**
     * 获取所有购物车商品列表
     *
     * @param $customerId
     * @return Builder[]|Collection
     */
    public static function allCartProducts($customerId)
    {
        return self::allCartProductsBuilder($customerId)->get();
    }

    /**
     * 当前购物车所有商品 builder
     *
     * @param $customerId
     * @return Builder
     */
    public static function allCartProductsBuilder($customerId): Builder
    {
        $builder =  CartProduct::query()
            ->with(['product.description', 'sku.product.description']);
        if ($customerId) {
            $builder->where('customer_id', $customerId);
        } else {
            $builder->where('session_id', get_session_id());
        }
        $builder->orderByDesc('id');

        return $builder;
    }

    /**
     * @param $customer
     * @return void
     */
    public static function mergeGuestCart($customer, $guestCartProduct): void
    {
        $guestCartProductSkus   = $guestCartProduct->pluck('product_sku');
        self::allCartProductsBuilder($customer->id)->whereIn('product_sku', $guestCartProductSkus)->delete();

        foreach ($guestCartProduct as $cartProduct) {
            $cartProduct->customer_id = $customer->id;
            $cartProduct->save();
        }
    }
}
