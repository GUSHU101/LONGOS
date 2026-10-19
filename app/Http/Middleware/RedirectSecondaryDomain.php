<?php

namespace App\Http\Middleware;

use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\Support\DomainName;
use App\Saas\Support\DomainRoutingPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RedirectSecondaryDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('saas.enabled')
            || ! config('saas.domains.redirect_secondary', true)) {
            return $next($request);
        }

        $site = Site::current();
        if (! $site) {
            return $next($request);
        }

        try {
            $host = DomainName::normalize($request->getHost());
        } catch (Throwable) {
            return $next($request);
        }

        $domains = $site->relationLoaded('domains') ? $site->domains : $site->domains()->get();
        $current = $domains->first(static fn (Domain $domain): bool => $domain->host === $host
            && $domain->isRoutable());

        if (! $current
            || ! DomainRoutingPolicy::shouldRedirect(
                $request->getMethod(),
                $request->path(),
                $current->is_primary,
                (string) $current->routing_behavior,
                $this->exemptPrefixes(),
            )) {
            return $next($request);
        }

        // Historical email URLs were signed with the exact host. Let a
        // still-valid absolute signature finish on that active secondary
        // domain; redirecting it would change the host and invalidate it.
        // New order links use relative signatures and can safely redirect.
        if ($request->has('signature') && $request->hasValidSignature()) {
            return $next($request);
        }

        $primary = $domains->first(static fn (Domain $domain): bool => $domain->is_primary
            && $domain->isRoutable());
        if (! $primary || $primary->host === $current->host) {
            return $next($request);
        }

        $uri = $request->getRequestUri();
        if ($uri === '' || $uri[0] !== '/' || preg_match('/[\x00-\x1F\x7F]/', $uri)) {
            $uri = '/';
        }

        $status = (int) config('saas.domains.redirect_status', 308);
        if (! in_array($status, [301, 302, 307, 308], true)) {
            $status = 308;
        }

        return redirect()->away("{$primary->scheme}://{$primary->host}{$uri}", $status);
    }

    /** @return array<int, string> */
    private function exemptPrefixes(): array
    {
        return array_merge(
            [trim((string) admin_name(), '/')],
            (array) config('saas.domains.redirect_exempt_paths', ['api', 'installer']),
        );
    }
}
