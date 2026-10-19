<?php

namespace App\Http\Middleware;

use App\Saas\TenantManager;
use Beike\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects operations that mutate code or other platform-wide resources.
 *
 * Tenant administrators may install, configure and enable packages already
 * approved by the platform. Downloading or updating package source is
 * different: the plugins directory is shared by every storefront and may
 * therefore only be changed from the LONGOS control site.
 */
class EnsureSaasPlatformAdministrator
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('saas.enabled')) {
            return $next($request);
        }

        abort_unless(Auth::guard(AdminUser::AUTH_GUARD)->user()?->is_root, 403);
        abort_unless((bool) data_get($this->tenants->current()?->meta, 'control_site', false), 403);
        abort_unless($this->tenants->isControlHost($request->getHost()), 403);

        return $next($request);
    }
}
