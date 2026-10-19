<?php

namespace App\Http\Middleware;

use Beike\Services\TimezoneService;
use Closure;

class SetAppTimezone
{
    public function handle($request, Closure $next)
    {
        // Invalid values from an old import must never make the whole site 500.
        TimezoneService::applyConfigured();

        return $next($request);
    }
}
