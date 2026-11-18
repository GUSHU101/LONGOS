<?php

namespace Plugin\LongosBotShield\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Plugin\LongosBotShield\Support\IpAddress;
use Throwable;

class IpInfoClient
{
    private const MAX_RESPONSE_BYTES = 524288;

    private const CONNECT_TIMEOUT_SECONDS = 0.3;

    private const REQUEST_TIMEOUT_SECONDS = 0.8;

    private const PROBE_CONNECT_TIMEOUT_SECONDS = 1.0;

    private const PROBE_TIMEOUT_SECONDS = 3.0;

    public function __construct(
        private readonly BotShieldSettings $settings,
        private readonly ProviderRequestBudget $requestBudget,
    ) {}

    public function available(): bool
    {
        return $this->settings->ipinfoToken() !== '';
    }

    /** @return array{healthy: bool, status: string, provider: string, latency_ms?: int, ip?: string, asn?: string, name?: string, domain?: string, country_code?: string, country_name?: string, continent_code?: string, continent_name?: string} */
    public function probe(?string $token = null, ?string $targetIp = null): array
    {
        $token = trim($token ?? $this->settings->ipinfoToken());
        if ($token === '') {
            return $this->probeResult(false, 'missing');
        }
        if (preg_match('/^[\x21-\x7E]{6,2048}$/D', $token) !== 1) {
            return $this->probeResult(false, 'invalid_token');
        }
        $targetIp = IpAddress::normalize($targetIp ?? '8.8.8.8');
        if (! IpAddress::isPublic($targetIp)) {
            return $this->probeResult(false, 'invalid_target');
        }

        $started = hrtime(true);

        try {
            $response = $this->request(
                $token,
                self::PROBE_CONNECT_TIMEOUT_SECONDS,
                self::PROBE_TIMEOUT_SECONDS,
            )
                ->get($this->endpoint($targetIp));
            $latency = $this->elapsedMilliseconds($started);

            if (in_array($response->status(), [401, 403], true)) {
                return $this->probeResult(false, 'unauthorized', $latency);
            }
            if ($response->status() === 429) {
                return $this->probeResult(false, 'rate_limited', $latency);
            }
            if (! $response->successful() || strlen((string) $response->body()) > self::MAX_RESPONSE_BYTES) {
                return $this->probeResult(false, 'unavailable', $latency);
            }

            $payload = $response->json();
            if (! is_array($payload) || ! $this->validPayloadForIp($payload, $targetIp)) {
                return $this->probeResult(false, 'invalid_response', $latency);
            }

            $result = $this->normalize($payload);
            if ($result['asn'] === '') {
                return $this->probeResult(false, 'invalid_response', $latency);
            }
            $this->clearFailures($this->scope($token));
            $this->cacheSuccess($targetIp, $token, $result);

            return [
                'healthy'        => true,
                'status'         => 'healthy',
                'provider'       => 'ipinfo',
                'latency_ms'     => $latency,
                'ip'             => $targetIp,
                'asn'            => $result['asn'],
                'name'           => $result['name'],
                'domain'         => $result['domain'],
                'country_code'   => $result['country_code'],
                'country_name'   => $result['country_name'],
                'continent_code' => $result['continent_code'],
                'continent_name' => $result['continent_name'],
            ];
        } catch (Throwable) {
            return $this->probeResult(false, 'unavailable', $this->elapsedMilliseconds($started));
        }
    }

    /** @return array<string, bool|string> */
    public function lookupCached(string $ip): array
    {
        $ip = IpAddress::normalize($ip);
        if (! IpAddress::isPublic($ip)) {
            return $this->emptyResult('local');
        }

        $token = $this->settings->ipinfoToken();
        if ($token === '') {
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

    /**
     * @return array{
     *     status: string,
     *     provider: string,
     *     security_data_present: bool,
     *     asn: string,
     *     name: string,
     *     domain: string,
     *     country_code: string,
     *     country_name: string,
     *     continent_code: string,
     *     continent_name: string,
     *     type: string,
     *     company_name: string,
     *     company_domain: string,
     *     company_type: string,
     *     privacy_name: string,
     *     is_hosting: bool,
     *     is_anonymous: bool,
     *     is_proxy: bool,
     *     is_res_proxy: bool,
     *     is_vpn: bool,
     *     is_tor: bool,
     *     is_relay: bool,
     *     is_anycast: bool,
     *     is_bogon: bool
     * }
     */
    public function lookup(string $ip): array
    {
        $ip = IpAddress::normalize($ip);
        if (! IpAddress::isPublic($ip)) {
            return $this->emptyResult('local');
        }

        $token    = $this->settings->ipinfoToken();
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

        if ($token === '' || Cache::has($this->circuitKey($scope))) {
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

            $response = $this->request(
                $token,
                self::CONNECT_TIMEOUT_SECONDS,
                self::REQUEST_TIMEOUT_SECONDS,
            )
                ->get($this->endpoint($ip));

            if (strlen((string) $response->body()) > self::MAX_RESPONSE_BYTES) {
                $this->recordFailure($scope, $response->status());

                return $this->cacheFailure($cacheKey, $stale);
            }

            $payload = $response->json();
            if (! $response->successful()
                || ! is_array($payload)
                || ! $this->validPayloadForIp($payload, $ip)) {
                $this->recordFailure($scope, $response->status());

                return $this->cacheFailure($cacheKey, $stale);
            }

            $this->clearFailures($scope);
            $result = $this->normalize($payload);
            $this->cacheSuccess($ip, $token, $result);

            return $result;
        } catch (Throwable) {
            $this->recordFailure($scope);

            return $this->cacheFailure($cacheKey, $stale);
        } finally {
            Cache::forget($refreshKey);
        }
    }

    private function normalize(array $payload): array
    {
        $as        = is_array($payload['as'] ?? null) ? $payload['as'] : [];
        $company   = is_array($payload['company'] ?? null) ? $payload['company'] : [];
        $anonymous = is_array($payload['anonymous'] ?? null) ? $payload['anonymous'] : [];
        $privacy   = is_array($payload['privacy'] ?? null) ? $payload['privacy'] : [];
        $asn       = strtoupper(trim((string) ($as['asn'] ?? $payload['asn'] ?? '')));
        $name      = trim((string) ($as['name'] ?? $as['org'] ?? $payload['as_name'] ?? ''));

        // The legacy endpoint may return `org` as "AS123 Example LLC".
        $legacyOrg = trim((string) ($payload['org'] ?? ''));
        if ($asn === '' && preg_match('/\bAS\d+\b/i', $legacyOrg, $match) === 1) {
            $asn = strtoupper($match[0]);
        }
        if ($name === '' && $legacyOrg !== '') {
            $name = trim((string) preg_replace('/^AS\d+\s*/i', '', $legacyOrg));
        }

        $isProxy            = $this->flag($anonymous, 'is_proxy') || $this->flag($privacy, 'proxy');
        $isResidentialProxy = $this->flag($payload, 'is_res_proxy')
                                                             || $this->flag($anonymous, 'is_res_proxy')
                                                             || $this->flag($privacy, 'is_res_proxy')
                                                             || $this->flag($privacy, 'residential_proxy');
        $isVpn         = $this->flag($anonymous, 'is_vpn')   || $this->flag($privacy, 'vpn');
        $isTor         = $this->flag($anonymous, 'is_tor')   || $this->flag($privacy, 'tor');
        $isRelay       = $this->flag($anonymous, 'is_relay') || $this->flag($privacy, 'relay');
        $anonymousFlag = $this->flag($payload, 'is_anonymous')
            || $this->flag($anonymous, 'is_anonymous')
            || $this->flag($privacy, 'anonymous')
            || $isProxy
            || $isResidentialProxy
            || $isVpn
            || $isTor
            || $isRelay;
        $hostingDataPresent = array_key_exists('is_hosting', $payload)
            || array_key_exists('is_hosting', $anonymous)
            || array_key_exists('hosting', $privacy);
        $privacyDataPresent = array_key_exists('is_anonymous', $payload)
            || array_key_exists('is_proxy', $payload)
            || array_key_exists('is_vpn', $payload)
            || array_key_exists('is_tor', $payload)
            || array_key_exists('anonymous', $payload)
            || array_key_exists('privacy', $payload);
        $securityDataPresent = $hostingDataPresent && $privacyDataPresent;
        $location            = $this->normalizeLocation($payload);

        return [
            'status'                => 'ok',
            'provider'              => 'ipinfo',
            'security_data_present' => $securityDataPresent,
            'asn'                   => $asn,
            'name'                  => $name,
            'domain'                => trim((string) ($as['domain'] ?? $payload['as_domain'] ?? '')),
            'country_code'          => $location['country_code'],
            'country_name'          => $location['country_name'],
            'continent_code'        => $location['continent_code'],
            'continent_name'        => $location['continent_name'],
            'type'                  => mb_strtolower(trim((string) ($as['type'] ?? ''))),
            'company_name'          => trim((string) ($company['name'] ?? '')),
            'company_domain'        => trim((string) ($company['domain'] ?? '')),
            'company_type'          => mb_strtolower(trim((string) ($company['type'] ?? ''))),
            'privacy_name'          => trim((string) ($anonymous['name'] ?? $privacy['name'] ?? $privacy['service'] ?? '')),
            'is_hosting'            => $this->flag($payload, 'is_hosting')
                || $this->flag($anonymous, 'is_hosting')
                || $this->flag($privacy, 'hosting'),
            'is_anonymous' => $anonymousFlag,
            'is_proxy'     => $isProxy,
            'is_res_proxy' => $isResidentialProxy,
            'is_vpn'       => $isVpn,
            'is_tor'       => $isTor,
            'is_relay'     => $isRelay,
            'is_anycast'   => $this->flag($payload, 'is_anycast'),
            'is_bogon'     => $this->flag($payload, 'bogon') || $this->flag($payload, 'is_bogon'),
        ];
    }

    /**
     * @return array{country_code: string, country_name: string, continent_code: string, continent_name: string}
     */
    private function normalizeLocation(array $payload): array
    {
        $currentCountryCode = $this->twoLetterCode($payload['country_code'] ?? '');
        $countryValue       = trim((string) ($payload['country'] ?? ''));
        $legacyCountryCode  = $this->twoLetterCode($countryValue);
        $legacyCountryName  = trim((string) ($payload['country_name'] ?? ''));

        $currentContinentCode = $this->twoLetterCode($payload['continent_code'] ?? '');
        $continentValue       = trim((string) ($payload['continent'] ?? ''));
        $legacyContinentCode  = $this->twoLetterCode($continentValue);
        $legacyContinentName  = trim((string) ($payload['continent_name'] ?? ''));

        return [
            'country_code' => $currentCountryCode !== '' ? $currentCountryCode : $legacyCountryCode,
            'country_name' => $currentCountryCode !== ''
                ? ($countryValue !== '' ? $countryValue : $legacyCountryName)
                : ($legacyCountryCode !== '' ? $legacyCountryName : ($legacyCountryName !== '' ? $legacyCountryName : $countryValue)),
            'continent_code' => $currentContinentCode !== '' ? $currentContinentCode : $legacyContinentCode,
            'continent_name' => $currentContinentCode !== ''
                ? ($continentValue !== '' ? $continentValue : $legacyContinentName)
                : ($legacyContinentCode !== '' ? $legacyContinentName : ($legacyContinentName !== '' ? $legacyContinentName : $continentValue)),
        ];
    }

    private function twoLetterCode(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{2}$/D', $value) === 1 ? $value : '';
    }

    private function flag(array $payload, string $key): bool
    {
        return filter_var($payload[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function validPayloadForIp(array $payload, string $ip): bool
    {
        if (! empty($payload['error'])) {
            return false;
        }

        return array_key_exists('ip', $payload)
            && IpAddress::normalize((string) $payload['ip']) === $ip;
    }

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

    private function recordFailure(string $scope, int $status = 0): void
    {
        $failureKey = 'longos:bot-shield:ipinfo-failures:' . $scope;
        if (Cache::add($failureKey, 1, now()->addMinutes(2))) {
            $failures = 1;
        } else {
            $failures = (int) Cache::increment($failureKey);
        }

        $openSeconds = match (true) {
            in_array($status, [401, 403], true) => 600,
            $status === 429                     => 120,
            $failures >= 3                      => 60,
            default                             => 0,
        };
        if ($openSeconds > 0) {
            Cache::put($this->circuitKey($scope), true, now()->addSeconds($openSeconds));
        }
    }

    private function clearFailures(string $scope): void
    {
        Cache::forget('longos:bot-shield:ipinfo-failures:' . $scope);
        Cache::forget($this->circuitKey($scope));
    }

    private function circuitKey(string $scope): string
    {
        return 'longos:bot-shield:ipinfo-circuit:' . $scope;
    }

    private function emptyResult(string $status): array
    {
        return [
            'status'                => $status,
            'provider'              => 'ipinfo',
            'security_data_present' => false,
            'asn'                   => '',
            'name'                  => '',
            'domain'                => '',
            'country_code'          => '',
            'country_name'          => '',
            'continent_code'        => '',
            'continent_name'        => '',
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

    /** @return array{healthy: bool, status: string, provider: string, latency_ms?: int} */
    private function probeResult(bool $healthy, string $status, ?int $latency = null): array
    {
        $result = [
            'healthy'  => $healthy,
            'status'   => $status,
            'provider' => 'ipinfo',
        ];
        if ($latency !== null) {
            $result['latency_ms'] = $latency;
        }

        return $result;
    }

    private function request(string $token, float $connectTimeout, float $timeout): PendingRequest
    {
        $request = Http::acceptJson()
            ->withToken($token)
            ->connectTimeout($connectTimeout)
            ->timeout($timeout);

        // LONGOS production has IPv4 egress. Avoid a broken or slow IPv6
        // route consuming the entire conversion budget before IPv4 is tried.
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $request->withOptions([
                'curl' => [(int) constant('CURLOPT_IPRESOLVE') => (int) constant('CURL_IPRESOLVE_V4')],
            ]);
        }

        return $request;
    }

    private function elapsedMilliseconds(int $started): int
    {
        return max(0, (int) round((hrtime(true) - $started) / 1_000_000));
    }

    private function cacheSuccess(string $ip, string $token, array $result): void
    {
        $cacheKey = $this->cacheKey($ip, $token);
        Cache::put($cacheKey, $result, now()->addMinutes($this->settings->cacheMinutes()));
        Cache::put(
            $cacheKey . ':stale',
            $result,
            now()->addMinutes(min(20160, $this->settings->cacheMinutes() + 1440)),
        );
    }

    private function endpoint(string $ip): string
    {
        return 'https://api.ipinfo.io/' . $this->settings->ipinfoApiTier() . '/' . rawurlencode($ip);
    }

    private function cacheKey(string $ip, string $token): string
    {
        // v2 invalidates pre-country-policy entries that may contain a valid
        // ASN but no country_code. Reusing those entries would silently skip
        // an enabled CN/HK/MO decision until the old stale TTL expired.
        return 'longos:bot-shield:ipinfo:v2:' . $this->scope($token) . ':' . $this->settings->ipHash($ip);
    }

    private function scope(string $token): string
    {
        return hash('sha256', implode('|', [
            $this->settings->tenantKey(),
            $this->settings->ipinfoApiTier(),
            hash('sha256', $token),
        ]));
    }
}
