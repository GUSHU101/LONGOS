<?php

namespace Plugin\LongosGlobalTranslate\Providers;

use InvalidArgumentException;
use Plugin\LongosGlobalTranslate\Services\TextContract;

final class VolcengineProvider extends AbstractTranslationProvider
{
    private const HOST = 'translate.volcengineapi.com';

    private const REGION = 'cn-north-1';

    private const SERVICE = 'translate';

    public function id(): string
    {
        return 'volcengine';
    }

    public function validateCredentialShape(array $credentials): void
    {
        foreach (['access_key_id', 'secret_access_key'] as $key) {
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
        $batches = [];
        $batch   = [];
        $units   = 0;
        foreach ($segments as $segment) {
            $size = TextContract::units($segment)['codepoints'];
            if ($size > 4750) {
                throw new InvalidArgumentException('SEGMENT_TOO_LARGE');
            }
            if (count($batch) === 16 || $units + $size > 4750) {
                $batches[] = $batch;
                $batch     = [];
                $units     = 0;
            }
            $batch[] = $segment;
            $units += $size;
        }
        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    public function send(TranslationProviderRequest $request, array $credentials): TranslationProviderResponse
    {
        $this->validateCredentialShape($credentials);
        $batches = $this->buildBatches($request->segments, $request->sourceLocale, $request->targetLocale);
        if (count($batches) !== 1) {
            throw new InvalidArgumentException('VOLCENGINE_REQUEST_MUST_BE_ONE_BATCH');
        }
        $started = hrtime(true);
        $body    = json_encode([
            'SourceLanguage' => $this->languageCode($request->sourceLocale),
            'TargetLanguage' => $this->languageCode($request->targetLocale),
            'TextList'       => $request->segments,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $date     = gmdate('Ymd\\THis\\Z');
        $query    = ['Action' => 'TranslateText', 'Version' => '2020-06-01'];
        $headers  = $this->signedHeaders($query, $body, $date, $credentials);
        $response = $this->client->request('POST', 'https://' . self::HOST . '/', [
            'query'   => $query,
            'body'    => $body,
            'headers' => $headers,
            'timeout' => $request->timeoutSeconds,
        ]);
        $payload      = json_decode((string) $response->getBody(), true) ?: [];
        $metadata     = (array) ($payload['ResponseMetadata'] ?? []);
        $error        = (array) ($metadata['Error'] ?? []);
        $translations = array_map(static fn (mixed $item): mixed => is_array($item) ? ($item['Translation'] ?? null) : null, (array) ($payload['TranslationList'] ?? []));
        $valid        = $response->getStatusCode() === 200 && $error === [] && count($translations) === count($request->segments)
                                                           && ! in_array(null, $translations, true) && ! in_array('', $translations, true);
        if (! $valid) {
            $code = (string) ($error['Code'] ?? 'VOLCENGINE_RESPONSE_INVALID');

            return $this->response($payload, $started, $metadata['RequestId'] ?? null, $this->error($code));
        }

        return $this->response($payload, $started, $metadata['RequestId'] ?? null, null, array_values($translations));
    }

    /** @param array<string, string> $credentials @param array<string, string> $query
     *  @return array<string, string>
     */
    public function signedHeaders(array $query, string $body, string $date, array $credentials): array
    {
        $canonicalQuery   = $this->canonicalQuery($query);
        $contentType      = 'application/json';
        $canonicalHeaders = 'content-type:' . $contentType . "\n" . 'host:' . self::HOST . "\n" . 'x-date:' . $date . "\n";
        $signed           = 'content-type;host;x-date';
        $canonicalRequest = "POST\n/\n{$canonicalQuery}\n{$canonicalHeaders}\n{$signed}\n" . hash('sha256', $body);
        $day              = substr($date, 0, 8);
        $scope            = $day . '/' . self::REGION . '/' . self::SERVICE . '/request';
        $stringToSign     = "HMAC-SHA256\n{$date}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        $kDate            = hash_hmac('sha256', $day, $credentials['secret_access_key'], true);
        $kRegion          = hash_hmac('sha256', self::REGION, $kDate, true);
        $kService         = hash_hmac('sha256', self::SERVICE, $kRegion, true);
        $signingKey       = hash_hmac('sha256', 'request', $kService, true);
        $signature        = hash_hmac('sha256', $stringToSign, $signingKey);

        return [
            'Host'          => self::HOST,
            'Content-Type'  => $contentType,
            'X-Date'        => $date,
            'Authorization' => 'HMAC-SHA256 Credential=' . $credentials['access_key_id'] . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $signature,
            'Accept'        => 'application/json',
        ];
    }

    /** @param array<string, string> $query */
    private function canonicalQuery(array $query): string
    {
        ksort($query, SORT_STRING);
        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[] = rawurlencode($key) . '=' . rawurlencode($value);
        }

        return implode('&', $pairs);
    }

    private function error(string $code): string
    {
        return match ($code) {
            '-400'  => 'INVALID_INPUT',
            '-415'  => 'UNSUPPORTED_LANGUAGE',
            '-429'  => 'RATE_LIMIT',
            default => 'VOLCENGINE_' . preg_replace('/[^A-Z0-9_-]/i', '', $code),
        };
    }
}
