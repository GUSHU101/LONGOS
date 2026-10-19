<?php

namespace App\Http\Middleware;

use Beike\Services\CloudflareIpRangeService;
use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    public const CADDY_REQUEST_ATTRIBUTE = '_longos_authenticated_caddy_edge';

    /**
     * The trusted proxies for this application.
     *
     * @var array|string|null
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR   |
        Request::HEADER_X_FORWARDED_HOST  |
        Request::HEADER_X_FORWARDED_PORT  |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    /**
     * Trust forwarding headers only when the direct TCP peer belongs to an
     * official Cloudflare range. CF-Connecting-IP is copied into X-Forwarded-
     * For only after that check, so a direct client cannot forge its address.
     */
    protected function setTrustedProxyIpAddresses(Request $request): void
    {
        $trusted = array_values(array_filter(
            (array) config('longos.network.trusted_proxies', []),
            static fn (mixed $proxy): bool => is_string($proxy)
                && trim($proxy) !== ''
                && ! in_array(trim($proxy), ['*', '**', 'REMOTE_ADDR'], true),
        ));

        $proxyToken     = (string) config('longos.network.proxy_token', '');
        $providedToken  = (string) $request->header('X-Longos-Proxy-Token', '');
        $remoteAddress  = $request->server->get('REMOTE_ADDR');
        $caddyTrusted   = strlen($proxyToken) >= 32
            && is_string($remoteAddress)
            && $remoteAddress !== ''
            && hash_equals($proxyToken, $providedToken);
        $request->attributes->set(self::CADDY_REQUEST_ATTRIBUTE, $caddyTrusted);
        if ($caddyTrusted) {
            $trusted[] = $remoteAddress;
        }

        $ranges      = new CloudflareIpRangeService;
        $edgeAddress = $caddyTrusted
            ? trim((string) $request->header('X-Longos-Edge-Remote', ''))
            : (is_string($remoteAddress) ? $remoteAddress : '');
        $fromCloudflare = $edgeAddress !== '' && $ranges->contains($edgeAddress);

        if ($fromCloudflare) {
            $request->attributes->set(CloudflareIpRangeService::REQUEST_ATTRIBUTE, true);
            $visitorIp = $ranges->visitorIp($request);
            if ($visitorIp !== null) {
                // Caddy authenticates this middleware call with a random
                // shared token and overwrites X-Longos-Edge-Remote. A direct
                // client therefore cannot make an arbitrary CF-Connecting-IP
                // value trusted by spoofing either header.
                $request->headers->set('X-Forwarded-For', $visitorIp);
            } else {
                // Never fall back to a pre-existing X-Forwarded-For when the
                // authoritative Cloudflare visitor address is malformed. That
                // header may have originated with the browser.
                $request->headers->remove('X-Forwarded-For');
            }

            if (! $caddyTrusted) {
                $trusted = [...$trusted, ...$ranges->ranges()];
            }
        }

        $trusted = array_values(array_unique($trusted));
        if ($trusted !== []) {
            $request->setTrustedProxies($trusted, $this->getTrustedHeaderNames());
        }

        // Internal authentication material is consumed at the trust boundary
        // and must not be visible to controllers, plugins, logs or hooks.
        $request->headers->remove('X-Longos-Proxy-Token');
        $request->headers->remove('X-Longos-Edge-Remote');
        $request->headers->remove('CF-Connecting-IP');
        if (! $fromCloudflare) {
            // Country data is trustworthy only at the verified edge. Remove
            // forged copies so plugins cannot accidentally consume them.
            $request->headers->remove('CF-IPCountry');
        }
    }
}
