<?php

declare(strict_types=1);

namespace Plugin\BeikeShopTestSandbox\Middleware\Shop;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shop middleware registered only while this plugin is enabled.
 *
 * It is deliberately route/theme agnostic: every storefront theme reaches
 * the core `/checkout` route, so redirecting to a per-visit numeric
 * checkout-session path makes it the default checkout without changing any
 * theme template or payment-plugin
 * setting. Disabling this plugin removes the middleware and restores core
 * checkout automatically.
 */
final class ForceSandboxCheckout
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('beikeshop_test.checkout_takeover.enabled', false)) {
            return $next($request);
        }

        if ($request->is('checkout')) {
            if ($request->isMethod('GET')) {
                return redirect('/checkout/' . random_int(100_000_000_000, 999_999_999_999));
            }

            return response()->json([
                'success' => false,
                'message' => 'TEST 支付安全沙盒已接管结账；常规订单确认与收款方式不可用。',
            ], 409);
        }

        if ($request->is('orders/*/pay')) {
            return redirect('/checkout')
                ->with('warning', 'TEST 支付安全沙盒启用中，常规收款页面不可用。');
        }

        return $next($request);
    }
}
