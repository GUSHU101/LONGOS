<?php

namespace Plugin\LongosBotShield\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Plugin\LongosBotShield\Support\IpAddress;
use Throwable;

class CloudflareRadarClient
{
    private const ENDPOINT = 'https://api.cloudflare.com/client/v4/radar/entities/asns/ip';

    private const MAX_RESPONSE_BYTES = 524288;

    private const CONNECT_TIMEOUT_SECONDS = 0.15;

    private const REQUEST_TIMEOUT_SECONDS = 0.3;

    public function __construct(
        private readonly BotShieldSettings $settings,
        private readonly ProviderRequestBudget $requestBudget,
    ) {}

    public function available(): bool
    {
        return $this->settings->cloudflareRadarFallbackEnabled()
            && $this->settings->cloudflareRadarToken() !== '';
    }

    /** @return array<string, bool|string> */
    public function lookupCached(string $ip): array
    {
        $ip = IpAddress::normalize($ip);
        if (! IpAddress::isPublic($ip)) {
            return $this->emptyResult('local');
        }

        $token = $this->settings->cloudflareRadarToken();
        if (! $this->settings->cloudflareRadarFallbackEnabled() || $token === '') {
            return $this->emptyResult('unavailable');
        }

        $cacheKey = $this->cacheKey($ip, $token);
        $cached   = Cache::get($cacheKey);
        if (is_array($cached) && ($cached['status'] ?? '') === 'ok') {
            $cached['cache_stale'] = false;

            return $cached;
        }

        $stale = Cache::get($cacheKey . ':stale');
        if (is_array($stale) && ($stale['status'] ?? '') === 'ok') {
            $stale['cache_stale'] = true;

            return $stale;
        }

        return is_array($cached) && isset($cached['status'])
            ? $cached
            : $this->emptyResult('unavailable');
    }

    /** @return array<string, bool|string> */
    public function lookup(string $ip): array
    {
        $ip    = IpAddress::normalize($ip);
        $token = $this->settings->cloudflareRadarToken();
        if (! IpAddress::isPublic($ip)) {
            return $this->emptyResult('local');
        }
        if (! $this->settings->cloudflareRadarFallbackEnabled() || $token === '') {
            return $this->emptyResult('unavailable');
        }

        $scope    = $this->scope($token);
        $cacheKey = $this->cacheKey($ip, $token);
        $cached   = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['status'])) {
            return $cached;
        }
        $stale = Cache::get($cacheKey . ':stale');
        if (! is_array($stale) || ($stale['status'] ?? '') !== 'ok') {
            $stale = null;
        }
        if (Cache::has($this->circuitKey($scope))) {
            return $stale ?? $this->emptyResult('unavailable');
        }

        $refreshKey = $cacheKey . ':refreshing';
        if (! Cache::add($refreshKey, true, now()->addSeconds(15))) {
            return $stale ?? $this->emptyResult('unavailable');
        }

        try {
            if (! $this->requestBudget->consume()) {
                return $this->emptyResult('unavailable');
            }

            $response = Http::acceptJson()
                ->withToken($token)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get(self::ENDPOINT, ['ip' => $ip]);

            if (strlen((string) $response->body()) > self::MAX_RESPONSE_BYTES) {
                $this->recordFailure($scope, $response->status(), $this->retryAfter($response->header('Retry-After')));

                return $this->cacheFailure($cacheKey, $stale);
            }

            $payload = $response->json();
            $asnData = is_array($payload) && is_array($payload['result']['asn'] ?? null)
                ? $payload['result']['asn']
                : [];
            $asn = filter_var($asnData['asn'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 4294967295],
            ]);
            if (! $response->successful()
                || ! is_array($payload)
                || ($payload['success'] ?? false) !== true
                || $asn === false) {
                $this->recordFailure($scope, $response->status(), $this->retryAfter($response->header('Retry-After')));

                return $this->cacheFailure($cacheKey, $stale);
            }

            $this->clearFailures($scope);
            $result = $this->normalize($asnData, (int) $asn);
            Cache::put($cacheKey, $result, now()->addMinutes($this->settings->cacheMinutes()));
            Cache::put($cacheKey . ':stale', $result, now()->addDays(7));

            return $result;
        } catch (Throwable) {
            $this->recordFailure($scope);

            return $this->cacheFailure($cacheKey, $stale);
        } finally {
            Cache::forget($refreshKey);
        }
    }

    /** @return array<string, bool|string> */
    private function normalize(array $payload, int $asn): array
    {
        $name    = trim((string) ($payload['name'] ?? ''));
        $orgName = trim((string) ($payload['orgName'] ?? ''));

        return [
            'status'                => 'ok',
            'provider'              => 'cloudflare-radar',
            // Radar's ASN endpoint supplies network identity, not IPInfo-style
            // hosting/privacy classifications. Never manufacture those flags.
            'security_data_present' => false,
            'asn'                   => 'AS' . $asn,
            'name'                  => $name !== '' ? $name : $orgName,
            'domain'                => $this->websiteHost((string) ($payload['website'] ?? '')),
            'type'                  => '',
            'company_name'          => $orgName !== '' ? $orgName : $name,
            'company_domain'        => '',
            'company_type'          => '',
            'privacy_name'          => '',
            'is_hosting'            => false,
            'is_anonymous'          => false,
            'is_proxy'              => false,
            'is_res_proxy'          => false,
            'is_vpn'                => false,
            'is_tor'                => false,
            'is_relay'              => false,
            'is_anycast'            => false,
            'is_bogon'              => false,
        ];
    }

    private function websiteHost(string $website): string
    {
        $host = parse_url(trim($website), PHP_URL_HOST);

        return is_string($host) ? mb_strtolower(rtrim($host, '.')) : '';
    }

    /** @return array<string, bool|string> */
    private function cacheFailure(string $cacheKey, ?array $stale = null): array
    {
        if ($stale !== null) {
            Cache::put($cacheKey, $stale, now()->addMinute());

            return $stale;
        }

        $result = $this->emptyResult('unavailable');
        Cache::put($cacheKey, $result, now()->addMinute());

        return $result;
    }

    private function recordFailure(string $scope, int $status = 0, int $retryAfter = 60): void
    {
        $failureKey = 'longos:bot-shield:cloudflare-radar-failures:' . $scope;
        if (Cache::add($failureKey, 1, now()->addMinutes(2))) {
            $failures = 1;
        } else {
            $failures = (int) Cache::increment($failureKey);
        }

        $openSeconds = match (true) {
            in_array($status, [401, 403], true) => 600,
            $status === 429                     => $retryAfter,
            $failures >= 3                      => 60,
            default                             => 0,
        };
        if ($openSeconds > 0) {
            Cache::put($this->circuitKey($scope), true, now()->addSeconds($openSeconds));
        }
    }

    private function retryAfter(mixed $value): int
    {
        return max(1, min(3600, (int) $value ?: 60));
    }

    private function clearFailures(string $scope): void
    {
        Cache::forget('longos:bot-shield:cloudflare-radar-failures:' . $scope);
        Cache::forget($this->circuitKey($scope));
    }

    private function circuitKey(string $scope): string
    {
        return 'longos:bot-shield:cloudflare-radar-circuit:' . $scope;
    }

    /** @return array<string, bool|string> */
    private function emptyResult(string $status): array
    {
        return [
            'status'                => $status,
            'provider'              => 'cloudflare-radar',
            'security_data_present' => false,
            'asn'                   => '',
            'name'                  => '',
            'domain'                => '',
            'type'                  => '',
            'company_name'          => '',
            'company_domain'        => '',
            'company_type'          => '',
            'privacy_name'          => '',
            'is_hosting'            => false,
            'is_anonymous'          => false,
            'is_proxy'              => false,
            'is_res_proxy'          => false,
            'is_vpn'                => false,
            'is_tor'                => false,
            'is_relay'              => false,
            'is_anycast'            => false,
            'is_bogon'              => false,
        ];
    }

    private function cacheKey(string $ip, string $token): string
    {
        return 'longos:bot-shield:cloudflare-radar:' . $this->scope($token) . ':' . $this->settings->ipHash($ip);
    }

    private function scope(string $token): string
    {
        return hash('sha256', $this->settings->tenantKey() . '|' . hash('sha256', $token));
    }
}
