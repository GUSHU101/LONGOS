<?php

namespace App\Http\Middleware;

use Beike\Services\LegacyRedirectService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectLegacyStorefrontPath
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() || $request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $path         = '/' . ltrim($request->path(), '/');
        $firstSegment = strtolower(strtok(ltrim($path, '/'), '/') ?: '');
        $adminPrefix  = strtolower(trim((string) config('beike.admin_name', 'admin'), '/'));
        if (in_array($firstSegment, array_unique([$adminPrefix, 'api', 'installer', '_longos', 'build', 'cache', 'image', 'vendor', 'storage']), true)
            || preg_match('/\.(?:avif|css|gif|ico|jpe?g|js|json|map|mp4|pdf|png|svg|webp|woff2?)$/i', $path)) {
            return $next($request);
        }

        $target = LegacyRedirectService::targetFor($path);
        if ($target === null) {
            return $next($request);
        }

        $parts       = parse_url($target) ?: [];
        $targetQuery = [];
        parse_str((string) ($parts['query'] ?? ''), $targetQuery);
        $query = array_replace($request->query(), $targetQuery);
        $path  = (string) ($parts['path'] ?? '/');
        $url   = $request->getSchemeAndHttpHost() . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return redirect()->away($url, 301);
    }
}
