<?php

/**
 * SetCurrencyShopApi.php
 *
 * @copyright  2023 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2023-08-25 16:40:19
 * @modified   2023-08-25 16:40:19
 */

namespace App\Http\Middleware;

use Beike\Services\VisitorCurrencyService;
use Closure;
use Illuminate\Http\Request;

class SetCurrencyShopApi
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $resolver = new VisitorCurrencyService;
        $resolved = $resolver->resolve($request, false);
        register('currency', $resolved['currency']);
        // Product/cart JSON is currency-aware and must never be shared between
        // visitors by an intermediary cache, including during USD fallback.
        $request->attributes->set('longos.currency.localized', true);
        $request->attributes->set('longos.currency.code', $resolved['currency']);
        $request->attributes->set('longos.currency.country', $resolved['country']);

        return $next($request);
    }
}
