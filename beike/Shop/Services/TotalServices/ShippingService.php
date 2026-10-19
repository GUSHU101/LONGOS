<?php

/**
 * ShippingService.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-22 17:58:14
 * @modified   2022-07-22 17:58:14
 */

namespace Beike\Shop\Services\TotalServices;

use Beike\Services\ShippingMethodService;
use Beike\Shop\Services\CheckoutService;

class ShippingService
{
    /**
     * @param CheckoutService $checkout
     * @return array|null
     * @throws \Exception|\Throwable
     */
    public static function getTotal(CheckoutService $checkout): ?array
    {
        $totalService   = $checkout->totalService;
        $shippingMethod = $totalService->getShippingMethod();
        if (empty($shippingMethod)) {
            return null;
        }

        $shippingMethods = $checkout->shippingMethods ?: ShippingMethodService::getShippingMethodsForCurrentCart($checkout);
        $selectedQuote   = ShippingMethodService::getCurrentQuote($shippingMethods, $shippingMethod);
        if (! is_array($selectedQuote)) {
            $cart                       = $checkout->cart;
            $cart->shipping_method_code = '';
            $extra                      = is_array($cart->extra) ? $cart->extra : [];
            unset($extra['shipping_quote_data']);
            $cart->extra = $extra;
            $cart->saveOrFail();

            return [];
        }

        // The quote shown to the customer is authoritative. Using that same
        // normalized quote for totals prevents a plugin's display amount and
        // its legacy getShippingFee() result from diverging at confirmation.
        $amount    = (float) ($selectedQuote['cost'] ?? 0);
        $amount    = is_finite($amount) ? max(0, $amount) : 0.0;
        $totalData = [
            'code'          => 'shipping',
            'title'         => trans('shop/carts.shipping_fee'),
            'amount'        => $amount,
            'amount_format' => currency_format($amount),
        ];

        $totalService->amount += $totalData['amount'];
        $totalService->totals[] = $totalData;

        return $totalData;
    }

    /**
     * 通过配送方式获取插件编码
     *
     * @param $shippingMethod
     * @return string
     */
    public static function parseShippingPluginCode($shippingMethod): string
    {
        $methodArray = explode('.', $shippingMethod);

        return $methodArray[0];
    }
}
