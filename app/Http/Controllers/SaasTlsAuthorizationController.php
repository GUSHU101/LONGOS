<?php

namespace App\Http\Controllers;

use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\Support\DomainName;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SaasTlsAuthorizationController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (config('saas.tls.driver') !== 'caddy') {
            return response('', 404, ['Cache-Control' => 'no-store']);
        }

        try {
            $host = DomainName::normalize((string) $request->query('domain', ''));
            if (config('saas.tls.external_control_domain') && $this->isControlDomain($host)) {
                return response('', 404, ['Cache-Control' => 'no-store']);
            }
            if (config('saas.enabled')) {
                $authorized = Domain::query()
                    ->where('host', $host)
                    ->where('scheme', 'https')
                    ->where(function ($query): void {
                        $query->routable()
                            ->orWhere(function ($query): void {
                                // A tenant-owned domain is allowed to obtain
                                // its first certificate while it is still
                                // fail-closed for normal storefront traffic.
                                // DomainVerificationService only enters this
                                // state after TXT ownership and traffic DNS
                                // have both been verified.
                                $query->where('status', Domain::STATUS_ACTIVATING)
                                    ->where('origin', Domain::ORIGIN_TENANT)
                                    ->whereNotNull('verified_at')
                                    ->whereNull('publication_error');
                            });
                    })
                    ->whereHas('site', static fn ($query) => $query->where('status', Site::STATUS_ACTIVE))
                    ->exists();
            } else {
                $authorized = installed()
                    && $host === DomainName::normalize((string) config('app.url'));
            }
        } catch (Throwable) {
            $authorized = false;
        }

        return response('', $authorized ? 204 : 404, ['Cache-Control' => 'no-store']);
    }

    private function isControlDomain(string $host): bool
    {
        foreach ((array) config('saas.control_domains', []) as $controlDomain) {
            try {
                if (DomainName::normalize((string) $controlDomain) === $host) {
                    return true;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return false;
    }
}
