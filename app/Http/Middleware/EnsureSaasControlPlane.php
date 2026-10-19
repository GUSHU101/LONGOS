<?php

namespace App\Http\Middleware;

use App\Saas\TenantManager;
use Beike\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSaasControlPlane
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('saas.enabled') && config('saas.http_management'), 404);
        abort_unless(Auth::guard(AdminUser::AUTH_GUARD)->user()?->is_root, 403);
        abort_unless($this->tenants->isControlHost($request->getHost()), 403);
        abort_unless((bool) data_get($this->tenants->current()?->meta, 'control_site', false), 403);

        return $next($request);
    }
}
