<?php

namespace Plugin\LongosBotShield\Services;

use App\Saas\Models\Site;
use Beike\Shop\Http\Controllers\HomeController;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Plugin\LongosBotShield\Support\IpAddress;
use Throwable;

class MainSiteHomepage
{
    public function __construct(private readonly InternalHomepageRequest $internalRequest) {}

    /** @return array{html: string, source: string}|null */
    public function render(): ?array
    {
        $current = current_site();
        if (! config('saas.enabled')) {
            return $this->renderCurrent();
        }
        if ($current === null) {
            return null;
        }
        if ((bool) data_get($current->meta, 'control_site', false)) {
            return $this->renderCurrent();
        }

        $origin = $this->controlOrigin($current);
        if ($origin === null) {
            // A child storefront must never substitute its own content when
            // the configured LONGOS control site is unavailable.
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
            return ['html' => $this->withBaseUrl($cached, $url), 'source' => 'control-cache'];
        }
        $stale = Cache::get($staleKey);
        if (! Cache::add($lockKey, true, now()->addSeconds(15))) {
            return is_string($stale) && $stale !== ''
                ? ['html' => $this->withBaseUrl($stale, $url), 'source' => 'control-cache-stale']
                : null;
        }

        try {
            $connectionOptions = $this->connectionOptions($host, $scheme);
            if ($connectionOptions === null) {
                return is_string($stale) && $stale !== ''
                    ? ['html' => $this->withBaseUrl($stale, $url), 'source' => 'control-cache-stale']
                    : null;
            }

            $internalHeader = $this->internalRequest->headerValue($host);
            if ($internalHeader === '') {
                return null;
            }

            $response = Http::accept('text/html')
                ->withUserAgent('LONGOS-BotShield-Homepage/1.0')
                ->withHeaders([
                    InternalHomepageRequest::HEADER => $internalHeader,
                ])
                ->connectTimeout(2.0)
                ->timeout(5.0)
                ->retry(2, 150, static function (Throwable $exception): bool {
                    return ! $exception instanceof RequestException
                        || $exception->response->serverError();
                }, throw: false)
                ->withOptions($connectionOptions)
                ->withoutRedirecting()
                ->get($url);

            $contentType = strtolower((string) $response->header('Content-Type'));
            $html        = (string) $response->body();
            if (! $response->successful()
                || ! str_contains($contentType, 'text/html')
                || $html === ''
                || strlen($html) > 2 * 1024 * 1024) {
                return is_string($stale) && $stale !== ''
                    ? ['html' => $this->withBaseUrl($stale, $url), 'source' => 'control-cache-stale']
                    : null;
            }

            $html = $this->withBaseUrl($html, $url);
            Cache::put($cacheKey, $html, now()->addMinute());
            Cache::put($staleKey, $html, now()->addMinutes(10));

            return ['html' => $html, 'source' => 'control'];
        } catch (Throwable) {
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
        try {
            $html = app(HomeController::class)->index()->render();
            if ($html === '' || strlen($html) > 2 * 1024 * 1024) {
                return null;
            }

            return [
                'html'   => $this->withBaseUrl($html, url('/')),
                'source' => 'current',
            ];
        } catch (Throwable) {
            return null;
        }
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
