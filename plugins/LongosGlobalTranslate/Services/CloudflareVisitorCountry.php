<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Beike\Services\CloudflareIpRangeService;
use Illuminate\Http\Request;

/**
 * Country of the visitor, supplied by a verified Cloudflare-to-origin request.
 *
 * Security boundary: headers are not evidence of Cloudflare on their own.
 * Read the actual TCP peer from REMOTE_ADDR; never use Request::ip(),
 * CF-Connecting-IP, X-Forwarded-For or another client-controllable header
 * to decide whether the edge's CF-IPCountry header is trustworthy.
 */
final class CloudflareVisitorCountry
{
    /**
     * Cloudflare's published origin-facing ranges, checked 2026-11-09.
     * Refresh alongside https://www.cloudflare.com/ips-v4 and /ips-v6.
     * New ranges fail closed until this list is updated.
     *
     * @var list<string>
     */
    public const EDGE_CIDRS = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22',
        '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22',
        '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
        '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public function countryCode(Request $request): ?string
    {
        if (! (bool) config('longos.translation.cloudflare_country_enabled', true)) {
            return null;
        }

        $code = self::normalizedCountry($request->headers->get('CF-IPCountry'));
        if ($code === null) {
            return null;
        }

        // LONGOS TrustProxies sets this attribute only after validating the
        // Coolify Caddy shared token and the original edge peer against the
        // refreshed Cloudflare ranges. Reuse that single trust decision so a
        // legitimate Caddy hop is not mistaken for an untrusted direct peer.
        if ($request->attributes->getBoolean(CloudflareIpRangeService::REQUEST_ATTRIBUTE)) {
            return $code;
        }

        $remote = $request->server->get('REMOTE_ADDR');
        if (! is_string($remote) || ! filter_var($remote, FILTER_VALIDATE_IP)) {
            return null;
        }

        // Direct Cloudflare-to-PHP development deployments use the same
        // shared, daily-refreshed range service as the platform middleware.
        if ((new CloudflareIpRangeService)->contains($remote)) {
            return $code;
        }

        // Some deployments have an additional *controlled* ingress between
        // Cloudflare and PHP. Its direct peer IP may be explicitly allowlisted
        // only after the operator configures that ingress to REMOVE any
        // attacker-supplied CF-IPCountry and replace it exclusively from a
        // verified Cloudflare request. Both flags are intentionally required.
        if (! (bool) config('longos.translation.cloudflare_proxy_headers_sanitized', false)) {
            return null;
        }
        $ranges = config('longos.translation.cloudflare_trusted_proxy_cidrs', []);
        if (! is_array($ranges)) {
            return null;
        }
        $ranges = array_values(array_filter($ranges, static fn (mixed $range): bool => is_string($range) && $range !== ''));

        return self::ipInRanges($remote, $ranges) ? $code : null;
    }

    public static function normalizedCountry(mixed $code): ?string
    {
        if (! is_string($code)) {
            return null;
        }
        $code = strtoupper(trim($code));

        // XX = unknown; T1 = Tor. EU/AP/ZZ are regions or placeholders,
        // not visitor countries. Do not create automatic locale matches.
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1 || in_array($code, ['XX', 'EU', 'AP', 'ZZ'], true)) {
            return null;
        }

        return $code;
    }

    /** @param list<string> $ranges */
    public static function ipInRanges(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (is_string($range) && self::ipInCidr($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    public static function ipInCidr(string $ip, string $range): bool
    {
        $parts = explode('/', $range, 2);
        if (count($parts) !== 2 || $parts[1] === '' || ! ctype_digit($parts[1])) {
            return false;
        }
        $address = @inet_pton($ip);
        $network = @inet_pton($parts[0]);
        if ($address === false || $network === false || strlen($address) !== strlen($network)) {
            return false;
        }
        $bits = (int) $parts[1];
        if ($bits < 0 || $bits > strlen($address) * 8) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        $remaining = $bits % 8;
        if ($remaining === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
