<?php

namespace App\Http\Middleware;

use Beike\Services\CurrencyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplySecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        if ($request->isSecure() && app()->environment('production')) {
            // Do not includeSubDomains: tenant custom domains may have sibling
            // hosts outside LONGOS control.
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        $private   = $this->containsPrivateData($request);
        $localized = $this->containsLocalizedRepresentation($request, $response);
        if ($private || $localized) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('CDN-Cache-Control', 'no-store');
            $response->headers->set('Cloudflare-CDN-Cache-Control', 'no-store');
        }

        if ($localized) {
            // Lets operators verify that the currently served PHP workers are
            // actually running the latest geo-currency policy after a Coolify
            // deployment, without exposing a visitor's country or currency.
            $response->headers->set('X-Longos-Currency-Policy', CurrencyService::POLICY_REVISION);
        }

        if ($private) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }

    private function containsLocalizedRepresentation(Request $request, Response $response): bool
    {
        if (! $request->attributes->getBoolean('longos.currency.localized') || ! $request->isMethodCacheable()) {
            return false;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type'));

        return str_starts_with($contentType, 'text/html') || str_contains($contentType, 'json');
    }

    private function containsPrivateData(Request $request): bool
    {
        $adminPath = trim(admin_name(), '/');

        return $request->is(
            '_longos/pay',
            '_longos/pay/*',
            $adminPath,
            $adminPath . '/*',
            'account',
            'account/*',
            'carts',
            'carts/*',
            'checkout',
            'checkout/*',
            'forgotten',
            'forgotten/*',
            'installer',
            'installer/*',
            'login',
            'longos-platform-pay',
            'longos-platform-pay/*',
            'logout',
            'orders',
            'orders/*',
            'products/autocomplete',
            'products/search',
            'register',
            'user_country',
        );
    }
}
