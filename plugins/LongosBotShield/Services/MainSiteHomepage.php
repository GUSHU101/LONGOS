<?php

namespace Plugin\LongosBotShield\Services;

use App\Http\Middleware\ShareViewData;
use App\Saas\Models\Site;
use Beike\Shop\Http\Controllers\HomeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Plugin\LongosBotShield\Support\IpAddress;
use Throwable;

class MainSiteHomepage
{
    public const CONNECT_TIMEOUT_SECONDS = 1.0;

    public const REQUEST_TIMEOUT_SECONDS = 5.0;

    private const MAXIMUM_HTML_BYTES = 8 * 1024 * 1024;

    private const FRESH_CACHE_MINUTES = 60;

    private const STALE_CACHE_HOURS = 24;

    private string $lastStatus = 'not_run';

    public function __construct(private readonly InternalHomepageRequest $internalRequest) {}

    /** @return array{html: string, source: string}|null */
    public function render(): ?array
    {
        $this->lastStatus = 'not_run';
        $current          = current_site();
        if (! config('saas.enabled')) {
            return $this->renderCurrent();
        }
        if ($current === null) {
            $this->lastStatus = 'site_context_unavailable';

            return null;
        }
        if ((bool) data_get($current->meta, 'control_site', false)) {
            return $this->renderCurrent();
        }

        $origin = $this->controlOrigin($current);
        if ($origin === null) {
            // A child storefront must never substitute its own content when
            // the configured LONGOS control site is unavailable.
            $this->lastStatus = 'control_origin_unavailable';

            return null;
        }

        $url    = $origin['url'];
        $host   = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        $cacheKey = 'longos:bot-shield:main-home:' . $origin['id'] . ':' . hash('sha256', $url);
        $staleKey = $cacheKey . ':stale';
        $lockKey  = $cacheKey . ':refreshing';
        $cached   = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            $this->lastStatus = 'ready_cache';

            return ['html' => $this->withBaseUrl($cached, $url), 'source' => 'control-cache'];
        }
        $stale = Cache::get($staleKey);
        if (! Cache::add($lockKey, true, now()->addSeconds(15))) {
            $this->lastStatus = is_string($stale) && $stale !== '' ? 'ready_stale' : 'refresh_in_progress';

            return is_string($stale) && $stale !== ''
                ? ['html' => $this->withBaseUrl($stale, $url), 'source' => 'control-cache-stale']
                : null;
        }

        try {
            $connectionOptions = $this->connectionOptions($host, $scheme);
            if ($connectionOptions === null) {
                $this->lastStatus = is_string($stale) && $stale !== ''
                    ? 'ready_stale'
                    : 'control_address_unavailable';

                return is_string($stale) && $stale !== ''
                    ? ['html' => $this->withBaseUrl($stale, $url), 'source' => 'control-cache-stale']
                    : null;
            }

            $internalHeader = $this->internalRequest->headerValue($host);
            if ($internalHeader === '') {
                $this->lastStatus = 'internal_auth_unavailable';

                return null;
            }

            $response = Http::accept('text/html')
                ->withUserAgent('LONGOS-BotShield-Homepage/1.0')
                ->withHeaders([
                    InternalHomepageRequest::HEADER => $internalHeader,
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->withOptions($connectionOptions)
                ->withoutRedirecting()
                ->get($url);

            $contentType = strtolower((string) $response->header('Content-Type'));
            $html        = (string) $response->body();
            if (! $response->successful()
                || ! str_contains($contentType, 'text/html')
                || $html === ''
                || strlen($html) > self::MAXIMUM_HTML_BYTES) {
                $this->lastStatus = is_string($stale) && $stale !== ''
                    ? 'ready_stale'
                    : $this->invalidResponseStatus($response->status(), $contentType, $html);

                return is_string($stale) && $stale !== ''
                    ? ['html' => $this->withBaseUrl($stale, $url), 'source' => 'control-cache-stale']
                    : null;
            }

            $html = $this->withBaseUrl($html, $url);
            Cache::put($cacheKey, $html, now()->addMinutes(self::FRESH_CACHE_MINUTES));
            Cache::put($staleKey, $html, now()->addHours(self::STALE_CACHE_HOURS));
            $this->lastStatus = 'ready';

            return ['html' => $html, 'source' => 'control'];
        } catch (Throwable) {
            $this->lastStatus = is_string($stale) && $stale !== '' ? 'ready_stale' : 'request_failed';

            return is_string($stale) && $stale !== ''
                ? ['html' => $this->withBaseUrl($stale, $url), 'source' => 'control-cache-stale']
                : null;
        } finally {
            Cache::forget($lockKey);
        }
    }

    /** @return array{html: string, source: string}|null */
    private function renderCurrent(): ?array
    {
        $this->lastStatus = 'not_run';
        $original         = request();
        $origin           = $original->getSchemeAndHttpHost() . '/';
        $siteKey          = current_site()?->getKey() ?? 'standalone';
        $cacheKey         = 'longos:bot-shield:current-home:' . hash('sha256', implode('|', [
            (string) $siteKey,
            $origin,
            app()->getLocale(),
        ]));
        $staleKey = $cacheKey . ':stale';
        $lockKey  = $cacheKey . ':refreshing';
        $cached   = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            $this->lastStatus = 'ready_cache';

            return ['html' => $cached, 'source' => 'current-cache'];
        }
        $stale = Cache::get($staleKey);
        if (! Cache::add($lockKey, true, now()->addSeconds(15))) {
            $this->lastStatus = is_string($stale) && $stale !== '' ? 'ready_stale' : 'refresh_in_progress';

            return is_string($stale) && $stale !== ''
                ? ['html' => $stale, 'source' => 'current-cache-stale']
                : null;
        }

        try {
            $url         = app('url');
            $urlRequest  = $url->getRequest();
            $homepageGet = Request::create($origin, 'GET', [], [], [], [
                'HTTP_HOST'       => $original->getHttpHost(),
                'HTTP_ACCEPT'     => 'text/html',
                'HTTP_USER_AGENT' => 'LONGOS-BotShield-Homepage/1.0',
                'REMOTE_ADDR'     => '127.0.0.1',
                'SERVER_PORT'     => $original->getPort(),
                'HTTPS'           => $original->isSecure() ? 'on' : 'off',
            ]);

            // Bot Shield runs before ShareViewData and the session stack.
            // Render against a clean GET / request so an in-scope visitor's
            // path, query string, cookies and body cannot influence the
            // control homepage. Restore both request bindings immediately.
            try {
                app()->instance('request', $homepageGet);
                $url->setRequest($homepageGet);
                app(ShareViewData::class)->loadShopShareViewData();
                $html = app(HomeController::class)->index()->render();
            } finally {
                app()->instance('request', $original);
                $url->setRequest($urlRequest);
            }
            if ($html === '' || strlen($html) > self::MAXIMUM_HTML_BYTES) {
                $this->lastStatus = $html === '' ? 'empty_html' : 'html_too_large';

                return null;
            }
            $html = $this->withBaseUrl($html, $origin);
            Cache::put($cacheKey, $html, now()->addMinutes(self::FRESH_CACHE_MINUTES));
            Cache::put($staleKey, $html, now()->addHours(self::STALE_CACHE_HOURS));
            $this->lastStatus = 'ready';

            return [
                'html'   => $html,
                'source' => 'current',
            ];
        } catch (Throwable) {
            $this->lastStatus = is_string($stale) && $stale !== '' ? 'ready_stale' : 'render_failed';

            return is_string($stale) && $stale !== ''
                ? ['html' => $stale, 'source' => 'current-cache-stale']
                : null;
        } finally {
            Cache::forget($lockKey);
        }
    }

    public function lastStatus(): string
    {
        return $this->lastStatus;
    }

    private function invalidResponseStatus(int $httpStatus, string $contentType, string $html): string
    {
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return 'upstream_http_' . $httpStatus;
        }
        if (! str_contains($contentType, 'text/html')) {
            return 'invalid_content_type';
        }
        if ($html === '') {
            return 'empty_html';
        }

        return 'html_too_large';
    }

    protected function controlSite(): ?Site
    {
        try {
            $control = Site::query()
                ->select(['id', 'status', 'meta'])
                ->where('status', Site::STATUS_ACTIVE)
                ->orderBy('id')
                ->cursor()
                ->first(static fn (Site $site): bool => (bool) data_get($site->meta, 'control_site', false));

            return $control?->load('domains');
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{id: int|string, url: string}|null */
    private function controlOrigin(Site $current): ?array
    {
        if ($current->getKey() === null) {
            return null;
        }

        $cacheKey = 'longos:bot-shield:control-origin:' . hash('sha256', (string) $current->getKey());
        $cached   = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['id'], $cached['url'])) {
            return $cached;
        }
        if (is_array($cached) && ($cached['missing'] ?? false) === true) {
            return null;
        }
        $refreshKey = $cacheKey . ':refreshing';
        if (! Cache::add($refreshKey, true, now()->addSeconds(10))) {
            return null;
        }

        try {
            $control = $this->controlSite();
            $url     = $this->normalizeOrigin($control?->primary_url);
            if ($control === null || $control->getKey() === null || $url === null) {
                // Cache a bounded negative result so a damaged control-plane
                // row cannot turn blocked traffic into a database query storm.
                Cache::put($cacheKey, ['missing' => true], now()->addSeconds(10));

                return null;
            }

            $origin = ['id' => $control->getKey(), 'url' => $url];
            Cache::put($cacheKey, $origin, now()->addMinute());

            return $origin;
        } finally {
            Cache::forget($refreshKey);
        }
    }

    private function normalizeOrigin(mixed $value): ?string
    {
        try {
            $parts = parse_url(trim((string) $value));
        } catch (Throwable) {
            return null;
        }
        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['https', 'http'], true)
            || (app()->environment('production') && $scheme !== 'https')) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && $port !== ($scheme === 'https' ? 443 : 80)) {
            return null;
        }

        $rawHost = strtolower(rtrim(trim((string) $parts['host'], '[]'), '.'));
        $ip      = IpAddress::normalize($rawHost);
        if ($ip !== '') {
            $host = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        } elseif (strlen($rawHost) > 253
            || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $rawHost) !== 1) {
            return null;
        } else {
            $host = $rawHost;
        }

        return $scheme . '://' . $host . '/';
    }

    private function withBaseUrl(string $html, string $url): string
    {
        if (preg_match('/<base\b[^>]*\bdata-longos-bot-shield=(["\'])1\1/i', $html) === 1) {
            return $html;
        }

        $base = '<base data-longos-bot-shield="1" href="'
            . htmlspecialchars(rtrim($url, '/') . '/', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '">';
        if (preg_match('/<head\b[^>]*>/i', $html) === 1) {
            return (string) preg_replace('/<head\b[^>]*>/i', '$0' . $base, $html, 1);
        }

        return $base . $html;
    }

    /**
     * Resolve and pin a public production address so a verified control
     * hostname cannot be changed into a DNS-rebinding SSRF target between
     * validation and the cURL connection.
     *
     * @return array<string, mixed>|null
     */
    private function connectionOptions(string $host, string $scheme): ?array
    {
        if (! app()->environment('production')) {
            return [];
        }

        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (! is_array($records)) {
                return null;
            }
            foreach ($records as $record) {
                $address = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
                if ($address !== '') {
                    $addresses[] = $address;
                }
            }
        }

        $public = array_values(array_filter(array_unique($addresses), static fn (string $address): bool => filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false));
        if ($public === []) {
            return null;
        }

        $address = $public[0];
        $port    = $scheme === 'https' ? 443 : 80;
        if (str_contains($address, ':')) {
            $address = '[' . $address . ']';
        }

        return [
            'curl' => [
                CURLOPT_RESOLVE => ["{$host}:{$port}:{$address}"],
            ],
        ];
    }
}
