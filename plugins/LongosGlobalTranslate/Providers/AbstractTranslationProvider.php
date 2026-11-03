<?php

namespace Plugin\LongosGlobalTranslate\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use InvalidArgumentException;
use Plugin\LongosGlobalTranslate\Services\TextContract;

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
        if ($failure instanceof TransferException) {
            // A transfer exception can occur after a request left the process.
            return ['kind' => 'uncertain', 'code' => 'NETWORK_AFTER_SEND', 'definitely_unbilled' => false];
        }
        if ($failure instanceof TranslationProviderResponse) {
            $code = (string) ($failure->errorCode ?? 'UNKNOWN_PROVIDER_ERROR');
            if (in_array($code, ['INVALID_INPUT', 'UNSUPPORTED_LANGUAGE', 'AUTH', 'NOT_OPENED', 'RATE_LIMIT'], true)) {
                return ['kind' => 'definite_failure', 'code' => $code, 'definitely_unbilled' => true];
            }
        }

        return ['kind' => 'uncertain', 'code' => 'UNKNOWN_PROVIDER_FAILURE', 'definitely_unbilled' => false];
    }

    protected function response(array $payload, int $startedAt, ?string $requestId = null, ?string $errorCode = null, array $translations = []): TranslationProviderResponse
    {
        return new TranslationProviderResponse(
            $errorCode === null,
            $translations,
            $requestId,
            $errorCode,
            hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            max(0, (int) floor((hrtime(true) - $startedAt) / 1_000_000)),
        );
    }
}
