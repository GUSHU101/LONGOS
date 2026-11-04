<?php

namespace Plugin\LongosGlobalTranslate\Providers;

use InvalidArgumentException;
use Plugin\LongosGlobalTranslate\Services\TextContract;

final class BaiduProvider extends AbstractTranslationProvider
{
    private const ENDPOINT = 'https://fanyi-api.baidu.com/api/trans/vip/translate';

    public function id(): string
    {
        return 'baidu';
    }

    public function validateCredentialShape(array $credentials): void
    {
        foreach (['appid', 'secret'] as $key) {
            if (trim((string) ($credentials[$key] ?? '')) === '') {
                throw new InvalidArgumentException('INVALID_PROVIDER_CREDENTIALS');
            }
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
            if (TextContract::units($segment)['bytes'] > 5000) {
                throw new InvalidArgumentException('SEGMENT_TOO_LARGE');
            }
        }

        // One segment per request avoids unsafe line-delimiter reassembly.
        return array_map(static fn (string $segment): array => [$segment], $segments);
    }

    public function send(TranslationProviderRequest $request, array $credentials): TranslationProviderResponse
    {
        $this->validateCredentialShape($credentials);
        if (count($request->segments) !== 1 || ! $this->supports($request->sourceLocale, $request->targetLocale)) {
            throw new InvalidArgumentException('INVALID_BAIDU_REQUEST');
        }
        $started = hrtime(true);
        $q       = $request->segments[0];
        $salt    = bin2hex(random_bytes(12));
        // Sign the exact UTF-8 source before form encoding, per the public API contract.
        $fields = [
            'q'     => $q,
            'from'  => $this->languageCode($request->sourceLocale),
            'to'    => $this->languageCode($request->targetLocale),
            'appid' => $credentials['appid'],
            'salt'  => $salt,
            'sign'  => md5($credentials['appid'] . $q . $salt . $credentials['secret']),
        ];
        $response = $this->client->request('POST', self::ENDPOINT, [
            'form_params' => $fields,
            'headers'     => ['Accept' => 'application/json'],
            'timeout'     => $request->timeoutSeconds,
        ]);
        $payload = json_decode((string) $response->getBody(), true) ?: [];
        $result  = $payload['trans_result'][0]['dst'] ?? null;
        if ($response->getStatusCode() !== 200 || isset($payload['error_code']) || ! is_string($result) || trim($result) === '') {
            return $this->response($payload, $started, null, $this->error((string) ($payload['error_code'] ?? $response->getStatusCode())));
        }

        return $this->response($payload, $started, null, null, [$result]);
    }

    private function error(string $code): string
    {
        return match ($code) {
            // These errors are returned before a billable translation is
            // accepted. Do not reserve a user's free quota for them.
            '401', '403', '52003', '54001', '58000', '58002', '90107' => 'AUTH',
            '400', '54000' => 'INVALID_INPUT',
            '429', '54003', '54005' => 'RATE_LIMIT',
            '54004' => 'BALANCE_EXHAUSTED',
            '58001' => 'UNSUPPORTED_LANGUAGE',
            default => 'BAIDU_' . preg_replace('/[^A-Z0-9_]/i', '', $code),
        };
    }

    /**
     * Baidu's general translation API uses several historic language codes
     * that differ from the locale codes LONGOS stores for storefronts.
     */
    protected function languageCode(string $locale): ?string
    {
        return match (strtolower(str_replace('_', '-', trim($locale)))) {
            'zh-tw' => 'cht',
            'ja'    => 'jp',
            'ko'    => 'kor',
            'fr'    => 'fra',
            'es'    => 'spa',
            'ar'    => 'ara',
            'vi'    => 'vie',
            default => parent::languageCode($locale),
        };
    }
}
