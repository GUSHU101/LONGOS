<?php

namespace Plugin\LongosInstantTranslate\Providers;

use InvalidArgumentException;
use Plugin\LongosInstantTranslate\Services\TextContract;

/**
 * Google Cloud Translation Basic v2 adapter.
 *
 * Google accepts an API key in X-Goog-Api-Key.  Keeping it in a header avoids
 * leaking the credential into a URL, web-server access log or proxy trace.
 */
final class GoogleCloudProvider extends AbstractTranslationProvider
{
    private const ENDPOINT = 'https://translation.googleapis.com/language/translate/v2';

    public function id(): string
    {
        return 'google';
    }

    public function validateCredentialShape(array $credentials): void
    {
        if (trim((string) ($credentials['api_key'] ?? '')) === '') {
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

        return $this->safeBatches($segments);
    }

    public function send(TranslationProviderRequest $request, array $credentials): TranslationProviderResponse
    {
        $this->validateCredentialShape($credentials);
        if (count($this->buildBatches($request->segments, $request->sourceLocale, $request->targetLocale)) !== 1) {
            throw new InvalidArgumentException('GOOGLE_REQUEST_MUST_BE_ONE_BATCH');
        }
        $started = hrtime(true);
        $body    = [
            'q'      => $request->segments,
            'source' => $this->languageCode($request->sourceLocale),
            'target' => $this->languageCode($request->targetLocale),
            'format' => 'text',
        ];
        // This adapter intentionally implements the documented Basic v2
        // surface. Its only model is the built-in base model; an Advanced v3
        // resource name belongs to a different authentication/API contract
        // and must never be accepted here only to fail at dispatch time.
        // The Basic v2 request schema has no context/instruction field.
        // The safe grammar profile and terminology therefore stay local and
        // must never be appended to a merchant's source text.
        $response = $this->client->request('POST', self::ENDPOINT, [
            'json'    => $body,
            'headers' => [
                'Accept'         => 'application/json',
                'X-Goog-Api-Key' => (string) $credentials['api_key'],
            ],
            'timeout' => $request->timeoutSeconds,
        ]);
        $payload      = json_decode((string) $response->getBody(), true) ?: [];
        $items        = data_get($payload, 'data.translations', []);
        $translations = is_array($items) ? array_map(
            static fn (mixed $item): mixed => is_array($item) && is_string($item['translatedText'] ?? null)
                ? html_entity_decode($item['translatedText'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
                : null,
            $items,
        ) : [];
        if ($response->getStatusCode() !== 200 || count($translations) !== count($request->segments) || in_array(null, $translations, true) || in_array('', $translations, true)) {
            return $this->response($payload, $started, null, $this->error($response->getStatusCode(), $payload));
        }

        return $this->response($payload, $started, null, null, array_values($translations));
    }

    /** @param list<string> $segments @return list<list<string>> */
    private function safeBatches(array $segments): array
    {
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

    /** @param array<string, mixed> $payload */
    private function error(int $statusCode, array $payload): string
    {
        $error   = (array) ($payload['error'] ?? []);
        $status  = strtoupper((string) ($error['status'] ?? ''));
        $message = strtolower((string) ($error['message'] ?? ''));

        if ($statusCode === 400 || $status === 'INVALID_ARGUMENT') {
            return 'INVALID_INPUT';
        }
        if ($statusCode === 429) {
            return 'RATE_LIMIT';
        }
        if ($status === 'RESOURCE_EXHAUSTED' || str_contains($message, 'quota') || str_contains($message, 'billing')) {
            return 'BALANCE_EXHAUSTED';
        }
        if (in_array($statusCode, [401, 403], true) || in_array($status, ['UNAUTHENTICATED', 'PERMISSION_DENIED'], true)) {
            return 'AUTH';
        }

        return 'GOOGLE_' . preg_replace('/[^A-Z0-9_]/', '', $status ?: (string) $statusCode);
    }

    protected function languageCode(string $locale): ?string
    {
        return match (strtolower(str_replace('_', '-', trim($locale)))) {
            'zh-cn' => 'zh-CN',
            'zh-tw' => 'zh-TW',
            default => parent::languageCode($locale),
        };
    }
}
