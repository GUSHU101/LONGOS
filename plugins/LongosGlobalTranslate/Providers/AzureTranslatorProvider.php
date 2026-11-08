<?php

namespace Plugin\LongosGlobalTranslate\Providers;

use InvalidArgumentException;
use Plugin\LongosGlobalTranslate\Services\TextContract;

/** Microsoft Azure Translator Text v3 adapter. */
final class AzureTranslatorProvider extends AbstractTranslationProvider
{
    private const ENDPOINT = 'https://api.cognitive.microsofttranslator.com/translate';

    public function id(): string
    {
        return 'azure';
    }

    public function validateCredentialShape(array $credentials): void
    {
        if (trim((string) ($credentials['subscription_key'] ?? '')) === '') {
            throw new InvalidArgumentException('INVALID_PROVIDER_CREDENTIALS');
        }
        $region = strtolower(trim((string) ($credentials['region'] ?? '')));
        if ($region === '' || ! preg_match('/^(global|[a-z0-9-]{2,64})$/', $region)) {
            throw new InvalidArgumentException('INVALID_PROVIDER_CREDENTIALS');
        }
    }

    /** @param list<string> $segments @return list<list<string>> */
    public function buildBatches(array $segments, string $sourceLocale, string $targetLocale): array
    {
        $this->assertSegments($segments);
        if (! $this->supports($sourceLocale, $targetLocale)) {
            throw new InvalidArgumentException('UNSUPPORTED_LANGUAGE');
        }
        $batches = [];
        $batch   = [];
        $units   = 0;
        foreach ($segments as $segment) {
            $length = TextContract::units($segment)['utf16'];
            if ($length > 4500) {
                throw new InvalidArgumentException('SEGMENT_TOO_LARGE');
            }
            if (count($batch) === 16 || $units + $length > 4500) {
                $batches[] = $batch;
                $batch     = [];
                $units     = 0;
            }
            $batch[] = $segment;
            $units += $length;
        }
        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    public function send(TranslationProviderRequest $request, array $credentials): TranslationProviderResponse
    {
        $this->validateCredentialShape($credentials);
        if (count($this->buildBatches($request->segments, $request->sourceLocale, $request->targetLocale)) !== 1) {
            throw new InvalidArgumentException('AZURE_REQUEST_MUST_BE_ONE_BATCH');
        }
        $started = hrtime(true);
        $region  = strtolower(trim((string) $credentials['region']));
        $headers = [
            'Accept'                    => 'application/json',
            'Ocp-Apim-Subscription-Key' => (string) $credentials['subscription_key'],
            'X-ClientTraceId'           => $request->requestUuid,
        ];
        if ($region !== 'global') {
            $headers['Ocp-Apim-Subscription-Region'] = $region;
        }
        // Translator Text v3 does not provide a generic context field. Do
        // not turn local instructions into copy: term masking and quality
        // checks happen before/after this strictly public-text request.
        $response = $this->client->request('POST', self::ENDPOINT, [
            'query' => [
                'api-version' => '3.0',
                'from'        => $this->languageCode($request->sourceLocale),
                'to'          => $this->languageCode($request->targetLocale),
            ],
            'json'    => array_map(static fn (string $text): array => ['Text' => $text], $request->segments),
            'headers' => $headers,
            'timeout' => $request->timeoutSeconds,
        ]);
        $payload      = json_decode((string) $response->getBody(), true) ?: [];
        $translations = is_array($payload) ? array_map(
            static fn (mixed $item): mixed => is_array($item) && is_string(data_get($item, 'translations.0.text'))
                ? data_get($item, 'translations.0.text')
                : null,
            $payload,
        ) : [];
        if ($response->getStatusCode() !== 200 || count($translations) !== count($request->segments) || in_array(null, $translations, true) || in_array('', $translations, true)) {
            return $this->response($payload, $started, $response->getHeaderLine('X-RequestId') ?: null, $this->error($response->getStatusCode()));
        }

        return $this->response($payload, $started, $response->getHeaderLine('X-RequestId') ?: null, null, array_values($translations));
    }

    private function error(int $statusCode): string
    {
        return match ($statusCode) {
            400, 408 => 'INVALID_INPUT',
            429 => 'RATE_LIMIT',
            401, 403 => 'AUTH',
            default => 'AZURE_' . $statusCode,
        };
    }

    protected function languageCode(string $locale): ?string
    {
        return match (strtolower(str_replace('_', '-', trim($locale)))) {
            'zh-cn', 'zh' => 'zh-Hans',
            'zh-tw' => 'zh-Hant',
            default => parent::languageCode($locale),
        };
    }
}
