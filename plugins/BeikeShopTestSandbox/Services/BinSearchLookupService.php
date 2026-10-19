<?php

namespace Plugin\BeikeShopTestSandbox\Services;

use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Plugin\BeikeShopTestSandbox\Support\TestCardCatalog;

class BinSearchLookupService
{
    private const PROVIDER = 'binsearchlookup.com';

    public function enrichTestPan(string $pan, bool $force = false, array $context = []): array
    {
        $digits = TestCardCatalog::normalizePan($pan);
        $bin    = substr($digits, 0, min(8, strlen($digits)));

        if (! preg_match('/^\d{12,19}$/', $digits)) {
            return $this->failure('INVALID_PAN', 'TEST PAN must contain 12-19 digits.', $bin, '/validate');
        }

        // Whitelist mode permits only built-in synthetic PANs; open mode
        // intentionally permits any structurally valid PAN for full detection.
        if (! TestCardCatalog::allowsPan($digits)) {
            return $this->failure('CARD_NOT_ALLOWED', 'Whitelist mode permits only configured synthetic TEST PANs.', $bin, '/validate');
        }

        if (strlen($bin) < 6) {
            return $this->failure('INVALID_BIN', 'BIN must contain at least 6 digits.', $bin, '/validate');
        }

        if (! $this->enabled()) {
            return $this->failure('DISABLED', 'BIN intelligence is disabled.', $bin, '/validate');
        }

        if (! $this->configured()) {
            return $this->failure('NOT_CONFIGURED', 'BinSearchLookup credentials are not configured.', $bin, '/validate');
        }

        $panHash  = hash_hmac('sha256', $digits, (string) config('app.key'));
        $freshKey = 'beikeshop_test:bsl:validate:fresh:' . $panHash;
        $staleKey = 'beikeshop_test:bsl:validate:stale:' . $panHash;

        if (! $force) {
            $fresh = Cache::get($freshKey);
            if (is_array($fresh)) {
                $fresh['source'] = 'CACHE_VALIDATE';
                $fresh['status'] = 'OK';
                $this->audit($context, $fresh, 'CACHE_HIT');

                return $fresh;
            }
        }

        $gate = $this->providerGate();
        if (! $gate['allowed']) {
            $stale = Cache::get($staleKey);
            if (is_array($stale)) {
                $stale['source']      = 'STALE_CACHE';
                $stale['status']      = 'OK';
                $stale['staleReason'] = $gate['status'];
                $this->audit($context, $stale, 'STALE_HIT');

                return $stale;
            }

            return $this->failure(
                $gate['status'],
                $gate['message'],
                $bin,
                '/validate',
                null,
                ['source' => 'FALLBACK']
            );
        }

        $lock = Cache::lock('beikeshop_test:bsl:singleflight:' . $panHash, 8);

        try {
            return $lock->block(2, function () use (
                $digits,
                $bin,
                $freshKey,
                $staleKey,
                $force,
                $context
            ) {
                if (! $force) {
                    $fresh = Cache::get($freshKey);
                    if (is_array($fresh)) {
                        $fresh['source'] = 'CACHE_VALIDATE';
                        $fresh['status'] = 'OK';
                        $this->audit($context, $fresh, 'CACHE_HIT_AFTER_LOCK');

                        return $fresh;
                    }
                }

                $result = $this->callValidate($digits, $bin, $context);

                if (($result['status'] ?? null) === 'OK') {
                    Cache::put(
                        $freshKey,
                        $result,
                        max(60, (int) config('beikeshop_test.bin_lookup.cache_ttl_seconds', 86400))
                    );
                    Cache::put(
                        $staleKey,
                        $result,
                        max(3600, (int) config('beikeshop_test.bin_lookup.stale_ttl_seconds', 604800))
                    );

                    return $result;
                }

                $stale = Cache::get($staleKey);
                if (is_array($stale) && $this->mayUseStale($result['status'] ?? '')) {
                    $stale['source']      = 'STALE_CACHE';
                    $stale['status']      = 'OK';
                    $stale['staleReason'] = $result['status'] ?? 'UPSTREAM_ERROR';
                    $this->audit($context, $stale, 'STALE_AFTER_ERROR');

                    return $stale;
                }

                return $result;
            });
        } catch (\Throwable $e) {
            $stale = Cache::get($staleKey);
            if (is_array($stale)) {
                $stale['source']      = 'STALE_CACHE';
                $stale['status']      = 'OK';
                $stale['staleReason'] = 'SINGLEFLIGHT_ERROR';
                $this->audit($context, $stale, 'STALE_AFTER_LOCK_ERROR');

                return $stale;
            }

            return $this->failure('LOCK_ERROR', 'BIN lookup single-flight lock failed.', $bin, '/validate');
        }
    }

    public function lookupBin(string $bin, bool $force = false, array $context = []): array
    {
        $bin = (string) preg_replace('/\D+/', '', $bin);

        if (! preg_match('/^\d{6,8}$/', $bin)) {
            return $this->failure('INVALID_BIN', 'BIN must contain 6-8 digits.', $bin, '/lookup');
        }

        if (! TestCardCatalog::allowsProviderBin($bin)) {
            return $this->failure('BIN_NOT_ALLOWED', 'Whitelist mode permits only BINs derived from configured synthetic TEST cards.', $bin, '/lookup');
        }

        if (! $this->enabled()) {
            return $this->failure('DISABLED', 'BIN intelligence is disabled.', $bin, '/lookup');
        }

        if (! $this->configured()) {
            return $this->failure('NOT_CONFIGURED', 'BinSearchLookup credentials are not configured.', $bin, '/lookup');
        }

        $freshKey = 'beikeshop_test:bsl:lookup:fresh:' . $bin;
        $staleKey = 'beikeshop_test:bsl:lookup:stale:' . $bin;

        if (! $force) {
            $cached = Cache::get($freshKey);
            if (is_array($cached)) {
                $cached['source'] = 'CACHE_LOOKUP';
                $cached['status'] = 'OK';
                $this->audit($context, $cached, 'CACHE_HIT');

                return $cached;
            }
        }

        $gate = $this->providerGate();
        if (! $gate['allowed']) {
            $stale = Cache::get($staleKey);
            if (is_array($stale)) {
                $stale['source']      = 'STALE_CACHE';
                $stale['status']      = 'OK';
                $stale['staleReason'] = $gate['status'];
                $this->audit($context, $stale, 'STALE_HIT');

                return $stale;
            }

            return $this->failure($gate['status'], $gate['message'], $bin, '/lookup');
        }

        $result = $this->callLookup($bin, $context);

        if (($result['status'] ?? null) === 'OK') {
            Cache::put(
                $freshKey,
                $result,
                max(60, (int) config('beikeshop_test.bin_lookup.cache_ttl_seconds', 86400))
            );
            Cache::put(
                $staleKey,
                $result,
                max(3600, (int) config('beikeshop_test.bin_lookup.stale_ttl_seconds', 604800))
            );
        }

        return $result;
    }

    public function batchWarm(array $bins, array $context = []): array
    {
        $requestedBins = array_values(array_filter(array_map(
            static fn ($bin) => (string) preg_replace('/\D+/', '', (string) $bin),
            $bins
        ), static fn (string $bin): bool => $bin !== ''));

        if ($requestedBins === []) {
            return [
                'status'   => 'INVALID_BIN_LIST',
                'success'  => false,
                'provider' => self::PROVIDER,
                'results'  => [],
            ];
        }

        $invalidBins = array_filter(
            $requestedBins,
            static fn (string $bin): bool => ! TestCardCatalog::allowsProviderBin($bin)
        );

        if ($invalidBins !== []) {
            return [
                'status'   => 'BIN_NOT_ALLOWED',
                'success'  => false,
                'provider' => self::PROVIDER,
                'results'  => [],
            ];
        }

        $bins = array_values(array_unique($requestedBins));

        $bins = array_slice($bins, 0, 50);

        if (! $this->configured()) {
            return [
                'status'   => 'NOT_CONFIGURED',
                'success'  => false,
                'provider' => self::PROVIDER,
                'results'  => [],
            ];
        }

        $gate = $this->providerGate();
        if (! $gate['allowed']) {
            return [
                'status'   => $gate['status'],
                'success'  => false,
                'provider' => self::PROVIDER,
                'results'  => [],
            ];
        }

        $started   = microtime(true);
        $requestId = 'beike_batch_' . Str::uuid();

        try {
            $response = $this->requestWithRetry(
                'POST',
                '/lookup/batch',
                ['bins' => $bins],
                $requestId
            );

            $elapsed = (int) round((microtime(true) - $started) * 1000);

            if (! $response->successful()) {
                $result = $this->httpFailure($response, implode(',', $bins), '/lookup/batch', $requestId, $elapsed);
                $this->audit($context, $result, 'BATCH_FAILURE');

                return [
                    'status'     => $result['status'],
                    'success'    => false,
                    'provider'   => self::PROVIDER,
                    'results'    => [],
                    'httpStatus' => $response->status(),
                ];
            }

            $body              = $response->json();
            $rawResults        = is_array($body['results'] ?? null) ? $body['results'] : [];
            $normalizedResults = [];

            foreach ($rawResults as $raw) {
                $normalized = $this->normalizeLookupEnvelope($raw, 'LIVE_BATCH', $requestId, $elapsed);
                if (($normalized['status'] ?? null) !== 'OK') {
                    continue;
                }

                $bin = (string) ($normalized['bin'] ?? '');
                if ($bin !== '') {
                    Cache::put(
                        'beikeshop_test:bsl:lookup:fresh:' . $bin,
                        $normalized,
                        max(60, (int) config('beikeshop_test.bin_lookup.cache_ttl_seconds', 86400))
                    );
                    Cache::put(
                        'beikeshop_test:bsl:lookup:stale:' . $bin,
                        $normalized,
                        max(3600, (int) config('beikeshop_test.bin_lookup.stale_ttl_seconds', 604800))
                    );
                }

                $normalizedResults[] = $normalized;
                $this->audit($context, $normalized, 'BATCH_RESULT');
            }

            $this->recordSuccess();

            return [
                'status'   => 'OK',
                'success'  => true,
                'provider' => self::PROVIDER,
                'source'   => 'LIVE_BATCH',
                'summary'  => $body['summary'] ?? null,
                'quota'    => $body['quota']   ?? null,
                'results'  => $normalizedResults,
            ];
        } catch (\Throwable $e) {
            $this->recordFailure();

            return [
                'status'   => 'NETWORK_ERROR',
                'success'  => false,
                'provider' => self::PROVIDER,
                'results'  => [],
            ];
        }
    }

    public function quota(bool $force = false): array
    {
        if (! $this->configured()) {
            return [
                'status'   => 'NOT_CONFIGURED',
                'provider' => self::PROVIDER,
            ];
        }

        $cacheKey = 'beikeshop_test:bsl:quota';
        if (! $force) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $apiKey = $this->apiKey();
        $userId = $this->userId();

        try {
            $response = $this->http()
                ->withHeaders([
                    'X-API-Key'  => $apiKey,
                    'X-User-ID'  => $userId,
                    'User-Agent' => 'LONGOS-Test-Sandbox/2.8',
                ])
                ->get('/api/quota/' . rawurlencode($userId));

            if (! $response->successful()) {
                return [
                    'status'     => $response->status() === 429 ? 'RATE_LIMITED' : 'ERROR',
                    'httpStatus' => $response->status(),
                    'provider'   => self::PROVIDER,
                    'errorCode'  => $this->extractErrorCode($response),
                ];
            }

            $body = $response->json();

            $result = [
                'status'      => 'OK',
                'provider'    => self::PROVIDER,
                'accountType' => $body['accountType'] ?? null,
                'usage'       => $body['usage']       ?? null,
                'limits'      => $body['limits']      ?? null,
                'resetTimes'  => $body['resetTimes']  ?? null,
                'checkedAt'   => now()->toIso8601String(),
            ];

            Cache::put($cacheKey, $result, max(15, (int) config('beikeshop_test.bin_lookup.quota_cache_seconds', 60)));

            return $result;
        } catch (\Throwable $e) {
            return [
                'status'   => $this->connectionFailureStatus($e),
                'provider' => self::PROVIDER,
            ];
        }
    }

    public function health(bool $force = false): array
    {
        $cacheKey = 'beikeshop_test:bsl:health';

        if (! $force) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $started  = microtime(true);
            $response = $this->http()
                ->get('/health');

            $latency = (int) round((microtime(true) - $started) * 1000);
            $body    = $response->json();

            $result = [
                'status'            => $response->successful() ? 'OK' : 'ERROR',
                'httpStatus'        => $response->status(),
                'providerStatus'    => is_array($body) ? ($body['status'] ?? null) : null,
                'providerTimestamp' => is_array($body) ? ($body['timestamp'] ?? null) : null,
                'latencyMs'         => $latency,
                'checkedAt'         => now()->toIso8601String(),
                'provider'          => self::PROVIDER,
            ];

            Cache::put($cacheKey, $result, max(10, (int) config('beikeshop_test.bin_lookup.health_cache_seconds', 30)));

            return $result;
        } catch (\Throwable $e) {
            return [
                'status'    => $this->connectionFailureStatus($e),
                'provider'  => self::PROVIDER,
                'checkedAt' => now()->toIso8601String(),
            ];
        }
    }

    public function circuitState(): array
    {
        return [
            'open'       => (bool) Cache::get('beikeshop_test:bsl:circuit:open', false),
            'failures'   => (int) Cache::get('beikeshop_test:bsl:circuit:failures', 0),
            'openedAt'   => Cache::get('beikeshop_test:bsl:circuit:opened_at'),
            'retryAfter' => Cache::get('beikeshop_test:bsl:circuit:retry_after'),
        ];
    }

    private function callValidate(string $pan, string $bin, array $context): array
    {
        $started   = microtime(true);
        $requestId = 'beike_validate_' . Str::uuid();

        try {
            $response = $this->requestWithRetry(
                'POST',
                '/validate',
                ['cardNumber' => $pan],
                $requestId
            );

            $elapsed = (int) round((microtime(true) - $started) * 1000);

            if (! $response->successful()) {
                $result = $this->httpFailure($response, $bin, '/validate', $requestId, $elapsed);
                $this->audit($context, $result, 'LIVE_VALIDATE_FAILURE');

                return $result;
            }

            $body = $response->json();
            if (! is_array($body)) {
                $result = $this->failure(
                    'INVALID_RESPONSE',
                    'Unexpected /validate response.',
                    $bin,
                    '/validate',
                    $response->status(),
                    ['requestId' => $requestId, 'responseTimeMs' => $elapsed]
                );
                $this->recordFailure();
                $this->audit($context, $result, 'LIVE_VALIDATE_INVALID_RESPONSE');

                return $result;
            }

            $normalized = $this->normalizeData(
                $body['data'] ?? [],
                [
                    'status'           => 'OK',
                    'source'           => 'LIVE_VALIDATE',
                    'endpoint'         => '/validate',
                    'httpStatus'       => $response->status(),
                    'requestId'        => $requestId,
                    'bin'              => (string) (($body['data']['BIN'] ?? null) ?: $bin),
                    'cardNumberMasked' => $body['cardNumberMasked'] ?? null,
                    'lengthValid'      => isset($body['lengthValid']) ? (bool) $body['lengthValid'] : null,
                    'luhnValid'        => isset($body['luhnValid']) ? (bool) $body['luhnValid'] : null,
                    'responseTimeMs'   => (int) ($body['responseTime'] ?? $elapsed),
                    'lookedUpAt'       => now()->toIso8601String(),
                ]
            );

            $normalized['responseHash'] = $this->payloadHash($normalized);

            $this->recordSuccess();
            $this->audit($context, $normalized, 'LIVE_VALIDATE_SUCCESS');

            return $normalized;
        } catch (\Throwable $e) {
            $this->recordFailure();

            $result = $this->failure(
                'NETWORK_ERROR',
                'BinSearchLookup /validate network request failed.',
                $bin,
                '/validate',
                null,
                ['requestId' => $requestId]
            );
            $this->audit($context, $result, 'LIVE_VALIDATE_NETWORK_ERROR');

            return $result;
        }
    }

    private function callLookup(string $bin, array $context): array
    {
        $started   = microtime(true);
        $requestId = 'beike_lookup_' . Str::uuid();

        try {
            $response = $this->requestWithRetry(
                'GET',
                '/lookup',
                ['bin' => $bin],
                $requestId
            );

            $elapsed = (int) round((microtime(true) - $started) * 1000);

            if (! $response->successful()) {
                $result = $this->httpFailure($response, $bin, '/lookup', $requestId, $elapsed);
                $this->audit($context, $result, 'LIVE_LOOKUP_FAILURE');

                return $result;
            }

            $body       = $response->json();
            $normalized = $this->normalizeLookupEnvelope($body, 'LIVE_LOOKUP', $requestId, $elapsed);

            if (($normalized['status'] ?? null) === 'OK') {
                $this->recordSuccess();
            } else {
                $this->recordFailure();
            }

            $this->audit($context, $normalized, 'LIVE_LOOKUP');

            return $normalized;
        } catch (\Throwable $e) {
            $this->recordFailure();

            $result = $this->failure(
                'NETWORK_ERROR',
                'BinSearchLookup /lookup network request failed.',
                $bin,
                '/lookup',
                null,
                ['requestId' => $requestId]
            );
            $this->audit($context, $result, 'LIVE_LOOKUP_NETWORK_ERROR');

            return $result;
        }
    }

    private function normalizeLookupEnvelope(mixed $body, string $source, string $requestId, int $elapsed): array
    {
        if (! is_array($body)) {
            return $this->failure(
                'INVALID_RESPONSE',
                'Unexpected BIN lookup response.',
                '',
                '/lookup',
                null,
                ['requestId' => $requestId, 'responseTimeMs' => $elapsed]
            );
        }

        if (isset($body['success']) && $body['success'] === false) {
            return $this->failure(
                'PROVIDER_REJECTED',
                'BIN lookup provider returned success=false.',
                (string) ($body['bin'] ?? ''),
                '/lookup',
                (int) ($body['statusCode'] ?? 0),
                ['requestId' => $requestId, 'responseTimeMs' => $elapsed]
            );
        }

        $data = $body['data'] ?? $body;
        if (! is_array($data)) {
            return $this->failure(
                'INVALID_RESPONSE',
                'BIN lookup data object is missing.',
                (string) ($body['bin'] ?? ''),
                '/lookup',
                null,
                ['requestId' => $requestId, 'responseTimeMs' => $elapsed]
            );
        }

        $normalized = $this->normalizeData(
            $data,
            [
                'status'         => 'OK',
                'source'         => $source,
                'endpoint'       => '/lookup',
                'httpStatus'     => (int) ($body['statusCode'] ?? 200),
                'requestId'      => $requestId,
                'bin'            => (string) ($data['BIN'] ?? $body['bin'] ?? ''),
                'responseTimeMs' => (int) ($body['responseTime'] ?? $elapsed),
                'lookedUpAt'     => now()->toIso8601String(),
            ]
        );

        $normalized['responseHash'] = $this->payloadHash($normalized);

        return $normalized;
    }

    private function normalizeData(array $data, array $base): array
    {
        $normalized = $base + [
            'provider'    => self::PROVIDER,
            'brand'       => $this->stringOrNull($data['Brand'] ?? null),
            'type'        => $this->stringOrNull($data['Type'] ?? null),
            'category'    => $this->stringOrNull($data['Category'] ?? null),
            'prepaid'     => $this->boolOrNull($data['Prepaid'] ?? null),
            'issuer'      => $this->stringOrNull($data['Issuer'] ?? null),
            'issuerPhone' => $this->stringOrNull($data['IssuerPhone'] ?? null),
            'issuerUrl'   => $this->stringOrNull($data['IssuerUrl'] ?? null),
            'country'     => $this->stringOrNull($data['CountryName'] ?? null),
            'iso2'        => $this->stringOrNull($data['isoCode2'] ?? null),
            'iso3'        => $this->stringOrNull($data['isoCode3'] ?? null),
            'brandLogo'   => $this->stringOrNull($data['brandLogo'] ?? null),
            'similarBins' => is_array($data['similarBins'] ?? null)
                ? array_values(array_slice($data['similarBins'], 0, 20))
                : [],
            'providerCached' => (bool) ($data['cached'] ?? false),
        ];

        $normalized['resolvedFunding']   = $this->resolveFunding($data);
        $normalized['resolvedClassCode'] = $this->classCode($normalized['resolvedFunding']);

        return $normalized;
    }

    private function providerGate(): array
    {
        if ((bool) Cache::get('beikeshop_test:bsl:circuit:open', false)) {
            $retryAfter = Cache::get('beikeshop_test:bsl:circuit:retry_after');
            if ($retryAfter && now()->timestamp < (int) $retryAfter) {
                return [
                    'allowed' => false,
                    'status'  => 'CIRCUIT_OPEN',
                    'message' => 'BIN provider circuit breaker is open.',
                ];
            }

            Cache::forget('beikeshop_test:bsl:circuit:open');
            Cache::forget('beikeshop_test:bsl:circuit:retry_after');
        }

        if (! (bool) config('beikeshop_test.bin_lookup.quota_guard_enabled', true)) {
            return ['allowed' => true];
        }

        $quota = $this->quota();
        if (($quota['status'] ?? null) !== 'OK') {
            return ['allowed' => true];
        }

        $usage  = $quota['usage']  ?? [];
        $limits = $quota['limits'] ?? [];

        $monthUsed   = $this->numeric($usage['requestsThisMonth'] ?? null);
        $monthLimit  = $this->numeric($limits['requestsPerMonth'] ?? null);
        $minuteUsed  = $this->numeric($usage['requestsThisMinute'] ?? null);
        $minuteLimit = $this->numeric($limits['requestsPerMinute'] ?? null);

        $monthRatio = ($monthUsed !== null && $monthLimit && $monthLimit > 0)
            ? $monthUsed / $monthLimit
            : 0.0;
        $minuteRatio = ($minuteUsed !== null && $minuteLimit && $minuteLimit > 0)
            ? $minuteUsed / $minuteLimit
            : 0.0;

        $monthGuard  = (float) config('beikeshop_test.bin_lookup.monthly_quota_guard_ratio', 0.95);
        $minuteGuard = (float) config('beikeshop_test.bin_lookup.minute_quota_guard_ratio', 0.90);

        if ($monthRatio >= $monthGuard) {
            return [
                'allowed' => false,
                'status'  => 'QUOTA_GUARD_MONTH',
                'message' => 'Monthly BIN provider quota guard activated.',
            ];
        }

        if ($minuteRatio >= $minuteGuard) {
            return [
                'allowed' => false,
                'status'  => 'QUOTA_GUARD_MINUTE',
                'message' => 'Minute BIN provider quota guard activated.',
            ];
        }

        return ['allowed' => true];
    }

    private function requestWithRetry(string $method, string $path, array $payload, string $requestId): Response
    {
        $attempts      = max(1, min(3, (int) config('beikeshop_test.bin_lookup.retry_attempts', 2)));
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $request = $this->http()
                    ->withHeaders([
                        'X-API-Key'    => $this->apiKey(),
                        'X-User-ID'    => $this->userId(),
                        'X-Request-ID' => $requestId,
                        'User-Agent'   => 'LONGOS-Test-Sandbox/2.8',
                    ]);

                $response = match (strtoupper($method)) {
                    'POST'  => $request->post($path, $payload),
                    default => $request->get($path, $payload),
                };

                // Never retry auth, validation, access-denied, or rate-limit responses.
                if (in_array($response->status(), [400, 401, 403, 429], true)) {
                    return $response;
                }

                if ($response->status() < 500 || $attempt === $attempts) {
                    return $response;
                }
            } catch (ConnectionException $e) {
                $lastException = $e;
                if ($attempt === $attempts) {
                    throw $e;
                }
            }

            usleep(random_int(80_000, 220_000) * $attempt);
        }

        throw $lastException ?? new \RuntimeException('BIN provider request failed.');
    }

    private function httpFailure(
        Response $response,
        string $bin,
        string $endpoint,
        string $requestId,
        int $elapsed
    ): array {
        $status = match ($response->status()) {
            400     => 'INVALID_REQUEST',
            401     => 'AUTH_ERROR',
            403     => 'ACCESS_ERROR',
            429     => 'RATE_LIMITED',
            default => $response->status() >= 500 ? 'UPSTREAM_ERROR' : 'HTTP_ERROR',
        };

        if (in_array($status, ['UPSTREAM_ERROR', 'HTTP_ERROR'], true)) {
            $this->recordFailure();
        }

        return $this->failure(
            $status,
            'BinSearchLookup request failed.',
            $bin,
            $endpoint,
            $response->status(),
            [
                'requestId'      => $requestId,
                'responseTimeMs' => $elapsed,
                'errorCode'      => $this->extractErrorCode($response),
            ]
        );
    }

    private function recordSuccess(): void
    {
        Cache::forget('beikeshop_test:bsl:circuit:failures');
        Cache::forget('beikeshop_test:bsl:circuit:open');
        Cache::forget('beikeshop_test:bsl:circuit:opened_at');
        Cache::forget('beikeshop_test:bsl:circuit:retry_after');
    }

    private function recordFailure(): void
    {
        $window      = max(30, (int) config('beikeshop_test.bin_lookup.circuit_window_seconds', 60));
        $threshold   = max(2, (int) config('beikeshop_test.bin_lookup.circuit_failure_threshold', 3));
        $openSeconds = max(15, (int) config('beikeshop_test.bin_lookup.circuit_open_seconds', 60));

        $key      = 'beikeshop_test:bsl:circuit:failures';
        $failures = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $failures, $window);

        if ($failures >= $threshold) {
            Cache::put('beikeshop_test:bsl:circuit:open', true, $openSeconds);
            Cache::put('beikeshop_test:bsl:circuit:opened_at', now()->toIso8601String(), $openSeconds);
            Cache::put('beikeshop_test:bsl:circuit:retry_after', now()->addSeconds($openSeconds)->timestamp, $openSeconds);
        }
    }

    private function audit(array $context, array $result, string $event): void
    {
        try {
            DB::table('beikeshop_test_bin_events')->insert([
                'event_id'        => (string) Str::uuid(),
                'order_id'        => $context['orderId'] ?? null,
                'event'           => $event,
                'provider'        => self::PROVIDER,
                'endpoint'        => $result['endpoint']       ?? null,
                'bin_prefix'      => $result['bin']            ?? null,
                'source'          => $result['source']         ?? null,
                'status'          => $result['status']         ?? null,
                'http_status'     => $result['httpStatus']     ?? null,
                'response_ms'     => $result['responseTimeMs'] ?? null,
                'request_id'      => $result['requestId']      ?? null,
                'provider_cached' => isset($result['providerCached']) ? (bool) $result['providerCached'] : null,
                'response_hash'   => $result['responseHash'] ?? null,
                'error_code'      => $result['errorCode']    ?? null,
                'created_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            // Telemetry must never break the checkout demo.
        }
    }

    private function resolveFunding(array $data): ?string
    {
        $type     = strtoupper(trim((string) ($data['Type'] ?? '')));
        $category = strtoupper(trim((string) ($data['Category'] ?? '')));
        $prepaid  = $data['Prepaid'] ?? null;

        if (
            $prepaid === true              ||
            str_contains($type, 'PREPAID') ||
            str_contains($category, 'PREPAID')
        ) {
            return 'PREPAID';
        }

        if (str_contains($type, 'GIFT') || str_contains($category, 'GIFT')) {
            return 'GIFT';
        }

        if (str_contains($type, 'DEBIT')) {
            return 'DEBIT';
        }

        if (str_contains($type, 'CREDIT') || str_contains($type, 'CHARGE')) {
            return 'CREDIT';
        }

        return $type !== '' ? $type : null;
    }

    private function classCode(?string $funding): ?string
    {
        return match (strtoupper((string) $funding)) {
            'CREDIT', 'CHARGE' => 'C',
            'DEBIT'   => 'D',
            'PREPAID' => 'P',
            'GIFT'    => 'G',
            default   => null,
        };
    }

    private function mayUseStale(string $status): bool
    {
        return in_array($status, [
            'NETWORK_ERROR',
            'UPSTREAM_ERROR',
            'HTTP_ERROR',
            'RATE_LIMITED',
            'CIRCUIT_OPEN',
            'QUOTA_GUARD_MONTH',
            'QUOTA_GUARD_MINUTE',
        ], true);
    }

    private function failure(
        string $status,
        string $message,
        string $bin,
        string $endpoint,
        ?int $httpStatus = null,
        array $extra = []
    ): array {
        return array_merge([
            'status'            => $status,
            'source'            => 'FALLBACK',
            'provider'          => self::PROVIDER,
            'endpoint'          => $endpoint,
            'httpStatus'        => $httpStatus,
            'bin'               => $bin,
            'message'           => $message,
            'resolvedFunding'   => null,
            'resolvedClassCode' => null,
            'lookedUpAt'        => now()->toIso8601String(),
        ], $extra);
    }

    private function payloadHash(array $payload): string
    {
        $copy = $payload;
        unset($copy['requestId'], $copy['lookedUpAt'], $copy['responseTimeMs'], $copy['responseHash']);
        ksort($copy);

        return hash('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function extractErrorCode(Response $response): ?string
    {
        $body = $response->json();
        if (! is_array($body)) {
            return null;
        }

        foreach (['errorCode', 'code', 'error'] as $key) {
            if (isset($body[$key]) && is_scalar($body[$key])) {
                return (string) $body[$key];
            }
        }

        return null;
    }

    private function enabled(): bool
    {
        $value = $this->pluginSetting('bin_lookup_enabled');

        return $value === null || $value === ''
            ? (bool) config('beikeshop_test.bin_lookup.enabled', true)
            : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function configured(): bool
    {
        return $this->apiKey() !== '' && $this->userId() !== '';
    }

    private function baseUrl(): string
    {
        return rtrim($this->pluginSettingString(
            'bin_api_base_url',
            (string) config('beikeshop_test.bin_lookup.base_url', 'https://api.binsearchlookup.com')
        ), '/');
    }

    /**
     * The Windows PHP runtime used by some BeikeShop installations has no
     * configured CA file. Keep TLS verification enabled by supplying
     * Composer's maintained CA bundle when the system has none.
     */
    private function http(): PendingRequest
    {
        $configuredBundle = trim((string) config('beikeshop_test.bin_lookup.ca_bundle', ''));
        $caBundle         = $configuredBundle !== '' ? $configuredBundle : CaBundle::getSystemCaRootBundlePath();

        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->connectTimeout($this->connectTimeout())
            ->timeout($this->timeout())
            ->withOptions(['verify' => $caBundle]);
    }

    private function connectionFailureStatus(\Throwable $exception): string
    {
        return str_contains($exception->getMessage(), 'cURL error 60')
            ? 'TLS_CERTIFICATE_ERROR'
            : 'NETWORK_ERROR';
    }

    private function apiKey(): string
    {
        return $this->pluginSettingString(
            'bin_api_key',
            (string) config('beikeshop_test.bin_lookup.api_key', '')
        );
    }

    private function userId(): string
    {
        return $this->pluginSettingString(
            'bin_user_id',
            (string) config('beikeshop_test.bin_lookup.user_id', '')
        );
    }

    private function pluginSetting(string $name): mixed
    {
        return function_exists('plugin_setting')
            ? plugin_setting('beike_shop_test_sandbox.' . $name, null)
            : null;
    }

    private function pluginSettingString(string $name, string $fallback): string
    {
        $value = $this->pluginSetting($name);

        return $value === null || trim((string) $value) === ''
            ? trim($fallback)
            : trim((string) $value);
    }

    private function timeout(): int
    {
        return max(1, (int) config('beikeshop_test.bin_lookup.timeout_seconds', 5));
    }

    private function connectTimeout(): int
    {
        return max(1, min($this->timeout(), (int) config('beikeshop_test.bin_lookup.connect_timeout_seconds', 3)));
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
