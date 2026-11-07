<?php

namespace Plugin\LongosGlobalTranslate\Providers;

use InvalidArgumentException;
use Plugin\LongosGlobalTranslate\Services\TextContract;

/** DeepL v2 translate adapter for API Free and API Pro accounts. */
final class DeepLProvider extends AbstractTranslationProvider
{
    private const FREE_ENDPOINT = 'https://api-free.deepl.com/v2/translate';

    private const PRO_ENDPOINT = 'https://api.deepl.com/v2/translate';

    public function id(): string
    {
        return 'deepl';
    }

    public function validateCredentialShape(array $credentials): void
    {
        if (trim((string) ($credentials['auth_key'] ?? '')) === '') {
            throw new InvalidArgumentException('INVALID_PROVIDER_CREDENTIALS');
        }
        if (! in_array(strtolower(trim((string) ($credentials['api_plan'] ?? ''))), ['free', 'pro'], true)) {
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
        foreach ($segments as $segment) {
            if (TextContract::units($segment)['utf16'] > 4500) {
                throw new InvalidArgumentException('SEGMENT_TOO_LARGE');
            }
        }

        // A single protected text segment per request yields deterministic
        // placeholder restoration and lets the ledger record every attempt.
        return array_map(static fn (string $segment): array => [$segment], $segments);
    }

    public function send(TranslationProviderRequest $request, array $credentials): TranslationProviderResponse
    {
        $this->validateCredentialShape($credentials);
        if (count($request->segments) !== 1 || ! $this->supports($request->sourceLocale, $request->targetLocale)) {
            throw new InvalidArgumentException('INVALID_DEEPL_REQUEST');
        }
        $started = hrtime(true);
        $body = [
            'text' => $request->segments,
            'source_lang' => $this->languageCode($request->sourceLocale),
            'target_lang' => $this->languageCode($request->targetLocale),
        ];
        // DeepL's translate endpoint formally supports source-language
        // context. It receives only the fixed profile prompt, never source
        // content, a visitor value, a checkout field or transaction data.
        $context = trim((string) ($request->context['prompt'] ?? ''));
        if ($context !== '') {
            $body['context'] = $context;
        }
        $endpoint = strtolower((string) $credentials['api_plan']) === 'free' ? self::FREE_ENDPOINT : self::PRO_ENDPOINT;
        $response = $this->client->request('POST', $endpoint, [
            'json' => $body,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'DeepL-Auth-Key ' . (string) $credentials['auth_key'],
            ],
            'timeout' => $request->timeoutSeconds,
        ]);
        $payload = json_decode((string) $response->getBody(), true) ?: [];
        $items = $payload['translations'] ?? [];
        $translations = is_array($items) ? array_map(
            static fn (mixed $item): mixed => is_array($item) && is_string($item['text'] ?? null) ? $item['text'] : null,
            $items,
        ) : [];
        if ($response->getStatusCode() !== 200 || count($translations) !== 1 || in_array(null, $translations, true) || in_array('', $translations, true)) {
            return $this->response($payload, $started, null, $this->error($response->getStatusCode(), $payload));
        }

        return $this->response($payload, $started, null, null, array_values($translations));
    }

    /** @param array<string, mixed> $payload */
    private function error(int $statusCode, array $payload): string
    {
        if ($statusCode === 400 || $statusCode === 413) {
            return 'INVALID_INPUT';
        }
        if ($statusCode === 429) {
            return 'RATE_LIMIT';
        }
        if ($statusCode === 456) {
            return 'BALANCE_EXHAUSTED';
        }
        if (in_array($statusCode, [401, 403], true)) {
            return 'AUTH';
        }

        return 'DEEPL_' . preg_replace('/[^A-Z0-9_]/', '', (string) $statusCode);
    }

    protected function languageCode(string $locale): ?string
    {
        return match (strtolower(str_replace('_', '-', trim($locale)))) {
            'en', 'en-us', 'en-gb' => 'EN',
            'pt', 'pt-br' => 'PT-PT',
            'zh-cn', 'zh' => 'ZH-HANS',
            'zh-tw' => 'ZH-HANT',
            default => ($code = parent::languageCode($locale)) === null ? null : strtoupper($code),
        };
    }
}
