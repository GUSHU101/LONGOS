<?php

namespace Plugin\LongosGlobalTranslate\Providers;

use InvalidArgumentException;
use Plugin\LongosGlobalTranslate\Services\TextContract;

final class AliyunProvider extends AbstractTranslationProvider
{
    private const HOST = 'mt.cn-hangzhou.aliyuncs.com';

    public function id(): string
    {
        return 'aliyun';
    }

    public function validateCredentialShape(array $credentials): void
    {
        foreach (['access_key_id', 'access_key_secret'] as $key) {
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
            if (TextContract::units($segment)['utf16'] > 4800) {
                throw new InvalidArgumentException('SEGMENT_TOO_LARGE');
            }
        }

        return array_map(static fn (string $segment): array => [$segment], $segments);
    }

    public function send(TranslationProviderRequest $request, array $credentials): TranslationProviderResponse
    {
        $this->validateCredentialShape($credentials);
        if (count($request->segments) !== 1 || ! $this->supports($request->sourceLocale, $request->targetLocale)) {
            throw new InvalidArgumentException('INVALID_ALIYUN_REQUEST');
        }
        $started = hrtime(true);
        $params  = [
            'Action'         => 'TranslateGeneral', 'Version' => '2018-10-12', 'Format' => 'JSON',
            'AccessKeyId'    => $credentials['access_key_id'], 'SignatureMethod' => 'HMAC-SHA1',
            'Timestamp'      => gmdate('Y-m-d\\TH:i:s\\Z'), 'SignatureVersion' => '1.0',
            'SignatureNonce' => bin2hex(random_bytes(16)), 'FormatType' => 'text', 'Scene' => 'general',
            'SourceLanguage' => $this->languageCode($request->sourceLocale),
            'TargetLanguage' => $this->languageCode($request->targetLocale), 'SourceText' => $request->segments[0],
        ];
        // Provider credentials and the signed source text must never be put
        // into a request URL.  The RPC endpoint accepts the same canonical
        // parameters as a POST form, where they are protected by TLS and do
        // not leak into edge/proxy request logs, browser histories, or URL
        // observability tools.
        $params['Signature'] = $this->signature($params, $credentials['access_key_secret'], 'POST');
        $http                = $this->client->request('POST', 'https://' . self::HOST . '/', [
            'form_params' => $params, 'timeout' => $request->timeoutSeconds, 'headers' => ['Accept' => 'application/json'],
        ]);
        $payload = json_decode((string) $http->getBody(), true) ?: [];
        // The RPC endpoint has existed in both a flat JSON form and an
        // operation-wrapped `TranslateGeneralResponse` form. Accept only the
        // documented wrapper, never a generic nested response, so malformed
        // provider output remains a conservative failure.
        $result     = is_array($payload['TranslateGeneralResponse'] ?? null) ? $payload['TranslateGeneralResponse'] : $payload;
        $code       = (int) ($result['Code'] ?? 0);
        $translated = $result['Data']['Translated'] ?? null;
        if ($http->getStatusCode() !== 200 || $code !== 200 || ! is_string($translated) || trim($translated) === '') {
            return $this->response($payload, $started, $result['RequestId'] ?? null, $this->error((string) ($result['Code'] ?? $http->getStatusCode())));
        }

        return $this->response($payload, $started, $result['RequestId'] ?? null, null, [$translated]);
    }

    /** @param array<string, string> $parameters */
    public function signature(array $parameters, string $secret, string $method = 'POST'): string
    {
        $method = strtoupper($method);
        if (! in_array($method, ['GET', 'POST'], true)) {
            throw new InvalidArgumentException('INVALID_ALIYUN_HTTP_METHOD');
        }
        ksort($parameters, SORT_STRING);
        $canonical    = $this->encodeQuery($parameters);
        $stringToSign = $method . '&%2F&' . rawurlencode($canonical);

        return base64_encode(hash_hmac('sha1', $stringToSign, $secret . '&', true));
    }

    /** @param array<string, string> $parameters */
    private function encodeQuery(array $parameters): string
    {
        $pairs = [];
        foreach ($parameters as $key => $value) {
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return str_replace('%7E', '~', implode('&', $pairs));
    }

    private function error(string $code): string
    {
        return match ($code) {
            // The general-edition API rejects malformed/oversized input
            // before it starts a translation, so the local free quota can be
            // released safely.
            '10003', '10004', '10008' => 'INVALID_INPUT',
            '10005' => 'UNSUPPORTED_LANGUAGE',
            '10009', '10010', '10013' => 'NOT_OPENED',
            // Timeouts and provider-side failures can occur after the
            // provider accepted bytes. Keep their quota occupied instead of
            // guessing that a replay would be free.
            '10001', '10002' => 'UNKNOWN_UPSTREAM',
            '429'   => 'RATE_LIMIT',
            default => 'ALIYUN_' . preg_replace('/[^A-Z0-9_]/i', '', $code),
        };
    }

    /**
     * Alibaba Cloud's general MT API follows BCP-47 for Traditional Chinese;
     * it does not use Baidu's `cht` code.  Keep this mapping local to the
     * provider so a tenant can switch provider without changing the LONGOS
     * storefront locale stored in its database.
     */
    protected function languageCode(string $locale): ?string
    {
        return match (strtolower(str_replace('_', '-', trim($locale)))) {
            'zh-tw' => 'zh-tw',
            default => parent::languageCode($locale),
        };
    }
}
