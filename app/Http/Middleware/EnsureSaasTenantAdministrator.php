<?php

namespace App\Http\Middleware;

use App\Saas\Models\Site;
use App\Saas\TenantManager;
use Beike\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSaasTenantAdministrator
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('saas.enabled') && config('saas.custom_domains.enabled', true), 404);
        abort_unless(Auth::guard(AdminUser::AUTH_GUARD)->user()?->is_root, 403);
        abort_unless($this->tenants->current()?->status === Site::STATUS_ACTIVE, 404);

        return $next($request);
    }
}
