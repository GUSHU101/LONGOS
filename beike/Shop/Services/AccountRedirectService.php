<?php

namespace Beike\Shop\Services;

use Illuminate\Http\Request;

class AccountRedirectService
{
    /**
     * Return a same-origin intended URL and consume it from the session.
     * External, malformed and protocol-relative targets fall back to account home.
     */
    public static function pullIntended(Request $request): string
    {
        $fallback = shop_route('account.index');
        $intended = $request->session()->pull('url.intended');

        if (! is_string($intended) || $intended === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $intended)) {
            return $fallback;
        }

        if (str_starts_with($intended, '//')) {
            return $fallback;
        }

        $parts = parse_url($intended);
        if ($parts === false) {
            return $fallback;
        }

        if (! isset($parts['host'])) {
            return str_starts_with($intended, '/') ? $intended : $fallback;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return $fallback;
        }

        if (! hash_equals(strtolower($request->getHost()), strtolower((string) $parts['host']))) {
            return $fallback;
        }

        $targetPort = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($targetPort !== $request->getPort()) {
            return $fallback;
        }

        return $intended;
    }
}
