<?php

namespace App\Http\Middleware;

use Beike\Services\VisitorCurrencyService;
use Closure;
use Illuminate\Http\Request;

class ResolveVisitorCurrency
{
    public function handle(Request $request, Closure $next): mixed
    {
        $resolver = new VisitorCurrencyService;
        $resolved = $resolver->resolve($request);
        register('currency', $resolved['currency']);
        // Storefront HTML is currency-aware even while the first rate snapshot
        // is unavailable. Keeping the USD fallback private prevents a CDN from
        // serving that stale bootstrap response after rates become available.
        $request->attributes->set('longos.currency.localized', true);
        $request->attributes->set('longos.currency.code', $resolved['currency']);
        $request->attributes->set('longos.currency.country', $resolved['country']);

        return $next($request);
    }
}
