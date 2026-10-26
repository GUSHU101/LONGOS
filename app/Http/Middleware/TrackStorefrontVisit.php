<?php

namespace App\Http\Middleware;

use Beike\Services\StorefrontVisitService;
use Closure;
use Illuminate\Http\Request;

class TrackStorefrontVisit
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->isMethod('GET')) {
            try {
                $request->attributes->set(
                    StorefrontVisitService::PAGE_TOKEN_ATTRIBUTE,
                    bin2hex(random_bytes(16)),
                );
            } catch (\Throwable) {
                // Entropy failure must not block the storefront. The service
                // safely skips confirmation when no page token is available.
            }
        }

        $response = $next($request);
        $visits   = app(StorefrontVisitService::class);

        try {
            // A successful HTML response only stages the visit. JavaScript
            // confirms it after real browser activity, so scanners that mimic
            // a browser user-agent no longer create countries or visitors.
            $visits->stage($request, $response);
        } catch (\Throwable $exception) {
            // Analytics must never make the storefront unavailable.
            $visits->reportFailure($request, $exception);
        }

        return $response;
    }
}
