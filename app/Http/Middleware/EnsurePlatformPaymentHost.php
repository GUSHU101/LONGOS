<?php

namespace App\Http\Middleware;

use App\Saas\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformPaymentHost
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('saas.enabled'), 404);
        abort_unless((bool) data_get($this->tenants->current()?->meta, 'control_site', false), 404);
        abort_unless($this->tenants->isControlHost($request->getHost()), 404);

        return $next($request);
    }
}
