<?php

namespace Plugin\LongosInstantTranslate\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use InvalidArgumentException;
use Plugin\LongosInstantTranslate\Services\TextContract;

abstract class AbstractTranslationProvider implements TranslationProviderInterface
{
    protected ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client([
            'http_errors'     => false,
            'allow_redirects' => false,
            'connect_timeout' => 3,
            'timeout'         => 12,
            'verify'          => true,
        ]);
    }

    public function supports(string $sourceLocale, string $targetLocale): bool
    {
        return $this->languageCode($sourceLocale) !== null
            && $this->languageCode($targetLocale) !== null
            && $this->languageCode($sourceLocale) !== $this->languageCode($targetLocale);
    }

    /** @param list<string> $segments */
    protected function assertSegments(array $segments): void
    {
        if ($segments === [] || count($segments) > 1000) {
            throw new InvalidArgumentException('INVALID_PROVIDER_SEGMENTS');
        }
        foreach ($segments as $segment) {
            if (! is_string($segment) || trim($segment) === '') {
                throw new InvalidArgumentException('INVALID_PROVIDER_SEGMENT');
            }
            TextContract::units($segment);
        }
    }

    protected function languageCode(string $locale): ?string
    {
        $locale = strtolower(str_replace('_', '-', trim($locale)));
        $map    = [
            'en' => 'en', 'en-us' => 'en', 'en-gb' => 'en',
            'zh' => 'zh', 'zh-cn' => 'zh', 'zh-tw' => 'cht',
            'fr' => 'fr', 'de' => 'de', 'es' => 'es', 'it' => 'it',
            'pt' => 'pt', 'pt-br' => 'pt', 'nl' => 'nl', 'pl' => 'pl',
            'ru' => 'ru', 'ja' => 'ja', 'ko' => 'ko', 'ar' => 'ar',
            'tr' => 'tr', 'vi' => 'vi', 'th' => 'th', 'id' => 'id',
        ];

        return $map[$locale] ?? null;
    }

    /** @return array{kind:string,code:string,definitely_unbilled:bool} */
    public function classifyFailure(\Throwable|TranslationProviderResponse $failure): array
    {
        if ($failure instanceof ConnectException) {
            // Guzzle raises ConnectException only while opening the transport
            // connection (DNS/TCP/TLS setup). At that point no HTTP request
            // body can have reached the translation provider, so this is safe
            // to fail over without risking a duplicate paid translation.
            return ['kind' => 'definite_failure', 'code' => 'NETWORK_CONNECT_FAILED', 'definitely_unbilled' => true];
        }
        if ($failure instanceof TransferException) {
            // A transfer exception can occur after a request left the process.
            return ['kind' => 'uncertain', 'code' => 'NETWORK_AFTER_SEND', 'definitely_unbilled' => false];
        }
        if ($failure instanceof InvalidArgumentException) {
            $code = strtoupper(trim($failure->getMessage()));
            if (preg_match('/^[A-Z0-9_]{3,96}$/', $code) === 1) {
                // Provider adapters throw these while validating a local
                // request or decoding local credentials, before their HTTP
                // client is invoked. They are safe to expose and release.
                return ['kind' => 'definite_failure', 'code' => $code, 'definitely_unbilled' => true];
            }
        }
        if ($failure instanceof TranslationProviderResponse) {
            $code = strtoupper(trim((string) ($failure->errorCode ?? 'UNKNOWN_PROVIDER_ERROR')));
            if (in_array($code, ['INVALID_INPUT', 'UNSUPPORTED_LANGUAGE', 'AUTH', 'NOT_OPENED', 'RATE_LIMIT', 'BALANCE_EXHAUSTED'], true)) {
                return ['kind' => 'definite_failure', 'code' => $code, 'definitely_unbilled' => true];
            }

            // Provider adapters already reduce upstream responses to a short
            // non-secret code (for example DEEPL_429 or AZURE_401). Preserve
            // that actionable code while keeping the conservative uncertain
            // billing state. Collapsing every response to UNKNOWN made the
            // control panel unable to tell an operator what must be fixed.
            if (preg_match('/^[A-Z0-9_]{3,96}$/', $code) === 1) {
                return ['kind' => 'uncertain', 'code' => $code, 'definitely_unbilled' => false];
            }
        }
        if ($failure instanceof \Throwable) {
            $code = strtoupper(trim($failure->getMessage()));
            if (preg_match('/^[A-Z0-9_]{3,96}$/', $code) === 1) {
                // A provider adapter or transport wrapper may surface a
                // stable diagnostic as an exception. Preserve it for the
                // operator but keep the attempt uncertain because the body
                // may already have left the process.
                return ['kind' => 'uncertain', 'code' => $code, 'definitely_unbilled' => false];
            }
        }

        return ['kind' => 'uncertain', 'code' => 'UNKNOWN_PROVIDER_FAILURE', 'definitely_unbilled' => false];
    }

    protected function response(array $payload, int $startedAt, ?string $requestId = null, ?string $errorCode = null, array $translations = [], ?int $totalTokens = null, ?int $retryAfterSeconds = null): TranslationProviderResponse
    {
        return new TranslationProviderResponse(
            $errorCode === null,
            $translations,
            $requestId,
            $errorCode,
            hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            max(0, (int) floor((hrtime(true) - $startedAt) / 1_000_000)),
            $totalTokens,
            $retryAfterSeconds,
        );
    }
}
