<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class MaintenanceMode
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (system_setting('base.maintenance_mode') && ! current_user()) {
            return response()->view('maintenance', []);
        }

        return $next($request);
    }
}
