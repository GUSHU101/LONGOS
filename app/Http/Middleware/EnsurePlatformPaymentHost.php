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
        $callbackPaymentRequest = self::isSessionPaymentRequest($request);
        abort_unless(
            $this->tenants->isControlHost($request->getHost())
                || ($callbackPaymentRequest && $this->accounts->isCallbackHost($request->getHost())),
            404,
        );

        return $next($request);
    }

    /**
     * Account callback aliases must not become alternate hosts for webhooks
     * or future payment endpoints. Only a capability-token session route can
     * retain an account alias in the browser; all other Platform Pay routes
     * require the canonical control host.
     */
    public static function isSessionPaymentRequest(Request $request): bool
    {
        $path = trim($request->path(), '/');

        return preg_match(
            '#^_longos/pay/[a-f0-9]{64}(?:/(?:status|paypal/(?:create|capture)|stripe/(?:intent|complete)|return/(?:paypal|stripe)|cancel))?$#D',
            $path,
        ) === 1;
    }
}
