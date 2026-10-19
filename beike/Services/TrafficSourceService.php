<?php

namespace Beike\Services;

use Illuminate\Http\Request;

class TrafficSourceService
{
    /** @return array{key:string,detail:?string,referer:?string} */
    public function resolve(Request $request): array
    {
        $referer        = $this->sanitizeReferer((string) $request->header('referer', ''));
        $internalHosts  = $this->internalHosts($request);
        $utmSource      = $this->cleanParameter($request->query('utm_source'));
        $utmMedium      = strtolower((string) $this->cleanParameter($request->query('utm_medium')));
        $campaignSource = $this->sourceFromCampaign($utmSource, $utmMedium, $request);

        if ($campaignSource !== null) {
            return [
                'key'     => $campaignSource,
                'detail'  => $utmSource ?: $campaignSource,
                'referer' => $referer,
            ];
        }

        $host = $this->host($referer);
        if ($host === '' || $this->matchesAny($host, $internalHosts)) {
            return ['key' => 'direct', 'detail' => null, 'referer' => $referer];
        }

        return [
            'key'     => $this->sourceFromHost($host),
            'detail'  => $host,
            'referer' => $referer,
        ];
    }

    /**
     * Classify historical product-view referrers without trusting arbitrary
     * request parameters. Used only as a rolling-upgrade fallback.
     */
    public function classifyReferer(?string $referer, array $internalHosts = []): string
    {
        $host = $this->host((string) $referer);
        if ($host === '' || $this->matchesAny($host, $internalHosts)) {
            return 'direct';
        }

        return $this->sourceFromHost($host);
    }

    private function sourceFromCampaign(?string $utmSource, string $utmMedium, Request $request): ?string
    {
        if ($this->cleanParameter($request->query('gclid')) !== null) {
            return 'google';
        }
        if ($this->cleanParameter($request->query('msclkid')) !== null) {
            return 'bing';
        }
        if ($this->cleanParameter($request->query('fbclid')) !== null) {
            return 'facebook';
        }
        if ($this->cleanParameter($request->query('ttclid')) !== null) {
            return 'tiktok';
        }
        if (in_array($utmMedium, ['email', 'e-mail', 'newsletter'], true)) {
            return 'email';
        }
        if (in_array($utmMedium, ['affiliate', 'affiliates', 'partner'], true)) {
            return 'affiliate';
        }
        if ($utmSource === null) {
            return null;
        }

        $normalized = strtolower($utmSource);
        $tokens     = preg_split('/[^a-z0-9.]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($this->sourceAliases() as $key => $aliases) {
            foreach ($aliases as $alias) {
                // Short aliases such as "ig", "fb" and "x" must only match
                // complete campaign tokens. Substring matching classified
                // unrelated values such as "original" as Instagram.
                if ($normalized === $alias || in_array($alias, $tokens, true)) {
                    return $key;
                }
            }
        }

        return 'other';
    }

    private function sourceFromHost(string $host): string
    {
        foreach ($this->sourceDomains() as $key => $domains) {
            if ($this->matchesAny($host, $domains)) {
                return $key;
            }
        }

        return 'other';
    }

    /** @return array<string, string[]> */
    private function sourceDomains(): array
    {
        return [
            'google'     => ['google.com', 'google.cn', 'google.co.uk', 'google.de', 'google.fr'],
            'baidu'      => ['baidu.com'],
            'bing'       => ['bing.com'],
            'yahoo'      => ['yahoo.com', 'search.yahoo.com'],
            'duckduckgo' => ['duckduckgo.com'],
            'yandex'     => ['yandex.com', 'yandex.ru'],
            'facebook'   => ['facebook.com', 'fb.com', 'l.facebook.com'],
            'instagram'  => ['instagram.com', 'l.instagram.com'],
            'twitter'    => ['twitter.com', 'x.com', 't.co'],
            'youtube'    => ['youtube.com', 'youtu.be'],
            'linkedin'   => ['linkedin.com', 'lnkd.in'],
            'tiktok'     => ['tiktok.com'],
            'pinterest'  => ['pinterest.com', 'pin.it'],
            'reddit'     => ['reddit.com', 'redd.it'],
        ];
    }

    /** @return array<string, string[]> */
    private function sourceAliases(): array
    {
        return [
            'google'     => ['google', 'adwords'],
            'baidu'      => ['baidu'],
            'bing'       => ['bing', 'microsoft'],
            'yahoo'      => ['yahoo'],
            'duckduckgo' => ['duckduckgo', 'ddg'],
            'yandex'     => ['yandex'],
            'facebook'   => ['facebook', 'meta', 'fb'],
            'instagram'  => ['instagram', 'ig'],
            'twitter'    => ['twitter', 'x.com', 'x'],
            'youtube'    => ['youtube'],
            'linkedin'   => ['linkedin'],
            'tiktok'     => ['tiktok'],
            'pinterest'  => ['pinterest'],
            'reddit'     => ['reddit'],
        ];
    }

    /** @return string[] */
    private function internalHosts(Request $request): array
    {
        $hosts = [$this->normalizeHost($request->getHost())];
        $site  = current_site();

        if ($site) {
            try {
                $domains = $site->relationLoaded('domains')
                    ? $site->domains->pluck('host')->all()
                    : $site->domains()->pluck('host')->all();
                foreach ($domains as $domain) {
                    $hosts[] = $this->normalizeHost((string) $domain);
                }
            } catch (\Throwable) {
                // The active request host is sufficient during a temporary
                // control-database outage.
            }
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    private function sanitizeReferer(string $referer): ?string
    {
        $referer = trim($referer);
        if ($referer === '' || strlen($referer) > 4096) {
            return null;
        }

        $parts = parse_url($referer);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = $this->normalizeHost((string) $parts['host']);
        if ($host === '') {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = isset($parts['path']) ? '/' . ltrim((string) $parts['path'], '/') : '';

        // Query strings and fragments can contain email addresses, tokens or
        // other customer data and are not required for source attribution.
        return mb_substr("{$scheme}://{$host}{$port}{$path}", 0, 1000);
    }

    private function cleanParameter(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 190);
    }

    private function host(?string $url): string
    {
        if ($url === null || $url === '') {
            return '';
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $this->normalizeHost($host) : '';
    }

    private function normalizeHost(string $host): string
    {
        return preg_replace('/^www\./i', '', strtolower(trim($host, " \t\n\r\0\x0B."))) ?? '';
    }

    /** @param string[] $domains */
    private function matchesAny(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            $domain = $this->normalizeHost((string) $domain);
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                return true;
            }
        }

        return false;
    }
}
