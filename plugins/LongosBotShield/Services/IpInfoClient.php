<?php

namespace Plugin\LongosBotShield\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Plugin\LongosBotShield\Support\IpAddress;
use Throwable;

class IpInfoClient
{
    private const MAX_RESPONSE_BYTES = 524288;

    public function __construct(
        private readonly BotShieldSettings $settings,
        private readonly ProviderRequestBudget $requestBudget,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     provider: string,
     *     security_data_present: bool,
     *     asn: string,
     *     name: string,
     *     domain: string,
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
        $scope    = hash('sha256', $this->settings->tenantKey() . '|' . hash('sha256', $token));
        $cacheKey = 'longos:bot-shield:ipinfo:' . $scope . ':' . $this->settings->ipHash($ip);
        $cached   = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['status'])) {
            return $cached;
        }

        if ($token === '' || Cache::has($this->circuitKey($scope))) {
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

            $response = Http::acceptJson()
                ->withToken($token)
                ->connectTimeout(1.0)
                ->timeout(2.5)
                ->retry(2, 100, static function (Throwable $exception): bool {
                    return ! $exception instanceof RequestException
                        || $exception->response->serverError();
                }, throw: false)
                ->get('https://api.ipinfo.io/lookup/' . rawurlencode($ip));

            if (strlen((string) $response->body()) > self::MAX_RESPONSE_BYTES) {
                $this->recordFailure($scope, $response->status());

                return $this->cacheFailure($cacheKey);
            }

            $payload = $response->json();
            if (! $response->successful()
                || ! is_array($payload)
                || ! $this->validPayloadForIp($payload, $ip)) {
                $this->recordFailure($scope, $response->status());

                return $this->cacheFailure($cacheKey);
            }

            $this->clearFailures($scope);
            $result = $this->normalize($payload);
            Cache::put($cacheKey, $result, now()->addMinutes($this->settings->cacheMinutes()));

            return $result;
        } catch (Throwable) {
            $this->recordFailure($scope);

            return $this->cacheFailure($cacheKey);
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
        $asn       = strtoupper(trim((string) ($as['asn'] ?? '')));
        $name      = trim((string) ($as['name'] ?? $as['org'] ?? ''));

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

        return [
            'status'                => 'ok',
            'provider'              => 'ipinfo',
            'security_data_present' => $securityDataPresent,
            'asn'                   => $asn,
            'name'                  => $name,
            'domain'                => trim((string) ($as['domain'] ?? '')),
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

    private function cacheFailure(string $cacheKey): array
    {
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
