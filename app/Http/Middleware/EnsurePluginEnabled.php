<?php

namespace App\Http\Middleware;

use Beike\Repositories\PluginRepo;
use Beike\Repositories\SettingRepo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsurePluginEnabled
{
    /**
     * Keep cached plugin routes available to every tenant while preventing a
     * tenant from reaching a plugin it has not installed and enabled.
     */
    public function handle(Request $request, Closure $next, string $pluginCode): Response
    {
        try {
            $enabled = PluginRepo::installed($pluginCode)
                && SettingRepo::getPluginStatus($pluginCode);
        } catch (Throwable) {
            // A missing tenant schema or unavailable connection must never
            // accidentally expose a plugin endpoint.
            $enabled = false;
        }

        abort_unless($enabled, 404);

        return $next($request);
    }
}
