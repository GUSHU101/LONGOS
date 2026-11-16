<?php

namespace Plugin\LongosBotShield\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Plugin\LongosBotShield\Support\IpAddress;
use Throwable;

class IpApiClient
{
    private const FIELDS = 'status,message,query,isp,org,as,asname,mobile,proxy,hosting';

    private const MAX_RESPONSE_BYTES = 524288;

    public function __construct(
        private readonly BotShieldSettings $settings,
        private readonly ProviderRequestBudget $requestBudget,
    ) {}

    public function available(): bool
    {
        return $this->settings->ipApiFallbackEnabled() && $this->settings->ipApiKey() !== '';
    }

    /** @return array<string, bool|string> */
    public function lookup(string $ip): array
    {
        $ip  = IpAddress::normalize($ip);
        $key = $this->settings->ipApiKey();
        if (! IpAddress::isPublic($ip)) {
            return $this->emptyResult('local');
        }
        if (! $this->settings->ipApiFallbackEnabled() || $key === '') {
            return $this->emptyResult('unavailable');
        }

        $scope    = hash('sha256', $this->settings->tenantKey() . '|' . hash('sha256', $key));
        $cacheKey = 'longos:bot-shield:ip-api:' . $scope . ':' . $this->settings->ipHash($ip);
        $cached   = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['status'])) {
            return $cached;
        }
        if (Cache::has($this->circuitKey($scope))) {
            return $this->emptyResult('unavailable');
        }
        $refreshKey = $cacheKey . ':refreshing';
        if (! Cache::add($refreshKey, true, now()->addSeconds(15))) {
            return $this->emptyResult('unavailable');
        }

        try {
            if (! $this->requestBudget->consume()) {
                return $this->emptyResult('unavailable');
            }

            // ip-api Pro requires the key as a query parameter. HTTPS keeps it
            // encrypted in transit; LONGOS never logs the request URL.
            $response = Http::acceptJson()
                ->connectTimeout(1.0)
                ->timeout(2.5)
                ->get('https://pro.ip-api.com/json/' . rawurlencode($ip), [
                    'key'    => $key,
                    'fields' => self::FIELDS,
                ]);

            if (strlen((string) $response->body()) > self::MAX_RESPONSE_BYTES) {
                $this->recordFailure($scope, $response->status(), $this->retryAfter($response->header('X-Ttl')));

                return $this->cacheFailure($cacheKey);
            }

            $payload = $response->json();
            if (! $response->successful()
                || ! is_array($payload)
                || ($payload['status'] ?? '')                               !== 'success'
                || IpAddress::normalize((string) ($payload['query'] ?? '')) !== $ip) {
                $this->recordFailure($scope, $response->status(), $this->retryAfter($response->header('X-Ttl')));

                return $this->cacheFailure($cacheKey);
            }

            $this->clearFailures($scope);
            $result = $this->normalize($payload);
            Cache::put($cacheKey, $result, now()->addMinutes($this->settings->cacheMinutes()));

            $remaining = filter_var($response->header('X-Rl'), FILTER_VALIDATE_INT);
            if ($remaining === 0) {
                Cache::put($this->circuitKey($scope), true, now()->addSeconds($this->retryAfter($response->header('X-Ttl'))));
            }

            return $result;
        } catch (Throwable) {
            $this->recordFailure($scope);

            return $this->cacheFailure($cacheKey);
        } finally {
            Cache::forget($refreshKey);
        }
    }

    /** @return array<string, bool|string> */
    private function normalize(array $payload): array
    {
        $as           = trim((string) ($payload['as'] ?? ''));
        $asn          = preg_match('/\bAS\d+\b/i', $as, $match) === 1 ? strtoupper($match[0]) : '';
        $organization = trim((string) ($payload['org'] ?? $payload['isp'] ?? ''));
        $asName       = trim((string) ($payload['asname'] ?? ''));
        $hosting      = $this->flag($payload, 'hosting');
        $proxy        = $this->flag($payload, 'proxy');

        return [
            'status'                => 'ok',
            'provider'              => 'ip-api',
            'security_data_present' => true,
            'asn'                   => $asn,
            'name'                  => $asName !== '' ? $asName : trim((string) preg_replace('/^AS\d+\s*/i', '', $as)),
            'domain'                => '',
            'type'                  => $hosting ? 'hosting' : '',
            'company_name'          => $organization,
            'company_domain'        => '',
            'company_type'          => $hosting ? 'hosting' : '',
            'privacy_name'          => '',
            'is_hosting'            => $hosting,
            'is_anonymous'          => $proxy,
            'is_proxy'              => $proxy,
            'is_res_proxy'          => false,
            // ip-api exposes one combined proxy/VPN/Tor flag, so do not
            // claim a more specific subtype than the provider returned.
            'is_vpn'     => false,
            'is_tor'     => false,
            'is_relay'   => false,
            'is_anycast' => false,
            'is_bogon'   => false,
        ];
    }

    private function flag(array $payload, string $key): bool
    {
        return filter_var($payload[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<string, bool|string> */
    private function cacheFailure(string $cacheKey): array
    {
        $result = $this->emptyResult('unavailable');
        Cache::put($cacheKey, $result, now()->addMinute());

        return $result;
    }

    private function recordFailure(string $scope, int $status = 0, int $retryAfter = 60): void
    {
        $failureKey = 'longos:bot-shield:ip-api-failures:' . $scope;
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
        Cache::forget('longos:bot-shield:ip-api-failures:' . $scope);
        Cache::forget($this->circuitKey($scope));
    }

    private function circuitKey(string $scope): string
    {
        return 'longos:bot-shield:ip-api-circuit:' . $scope;
    }

    /** @return array<string, bool|string> */
    private function emptyResult(string $status): array
    {
        return [
            'status'                => $status,
            'provider'              => 'ip-api',
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
}
