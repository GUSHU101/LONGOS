<?php

namespace App\Http\Middleware;

use App\Saas\TenantManager;
use App\Services\PlatformPayment\PlatformPaymentAccountService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformPaymentHost
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly PlatformPaymentAccountService $accounts,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('saas.enabled'), 404);
        abort_unless((bool) data_get($this->tenants->current()?->meta, 'control_site', false), 404);
        // The canonical control host remains valid for legacy sessions. A
        // managed receiver account additionally owns one platform-generated
        // callback host. It is still routed to the control site, but only
        // the payment middleware is allowed to serve from that alias.
        abort_unless(
            $this->tenants->isControlHost($request->getHost())
                || $this->accounts->isCallbackHost($request->getHost()),
            404,
        );

        return $next($request);
    }
}
