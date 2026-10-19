<?php

namespace App\Http\Middleware;

use Beike\Plugin\RuntimeGuard;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;

class RunEnabledPluginMiddleware
{
    public function handle(
        Request $request,
        Closure $next,
        string $pluginCode,
        string $middlewareClass,
    ): mixed {
        if (! RuntimeGuard::isActive($pluginCode)) {
            return $next($request);
        }

        if (! class_exists($middlewareClass)) {
            throw new RuntimeException("Enabled plugin middleware does not exist: {$middlewareClass}");
        }

        return app($middlewareClass)->handle($request, $next);
    }
}
