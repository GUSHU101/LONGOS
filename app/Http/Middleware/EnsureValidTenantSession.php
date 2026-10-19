<?php

namespace App\Http\Middleware;

use App\Saas\Models\Site;
use Closure;
use Spatie\Multitenancy\Http\Middleware\EnsureValidTenantSession as SpatieEnsureValidTenantSession;

class EnsureValidTenantSession extends SpatieEnsureValidTenantSession
{
    public function handle($request, Closure $next)
    {
        if (! config('saas.enabled') || ! Site::checkCurrent()) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
