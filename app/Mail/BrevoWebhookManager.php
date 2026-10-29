<?php

namespace App\Mail;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;

class BrevoWebhookManager
{
    private const API_BASE = 'https://api.brevo.com';

    private const EVENTS = [
        'sent',
        'delivered',
        'deferred',
        'softBounce',
        'hardBounce',
        'blocked',
        'invalid',
        'spam',
    ];

    public function __construct(
        private readonly BrevoSettings $settings,
        private readonly ?ClientInterface $client = null,
    ) {}

    /** @return array{id:int,created:bool,url:string} */
    public function sync(string $url): array
    {
        if (! str_starts_with(strtolower($url), 'https://')) {
            throw new RuntimeException('Brevo 回执地址必须使用 HTTPS。');
        }

        $runtime = $this->settings->runtime();
        if ($runtime['api_key'] === '' || $runtime['webhook_token'] === '') {
            throw new RuntimeException('请先保存 Brevo API Key。');
        }

        $client = $this->client ?? new Client([
            'base_uri'        => self::API_BASE,
            'connect_timeout' => 8,
            'timeout'         => $runtime['timeout'],
            'verify'          => true,
            'http_errors'     => false,
            'allow_redirects' => false,
            'headers'         => [
                'accept'  => 'application/json',
                'api-key' => $runtime['api_key'],
            ],
        ]);

        $listResponse = $client->get('/v3/webhooks', ['query' => ['type' => 'transactional', 'sort' => 'desc']]);
        $list         = json_decode((string) $listResponse->getBody(), true);
        if ($listResponse->getStatusCode() !== 200 || ! is_array($list)) {
            throw new RuntimeException($this->providerError($listResponse->getStatusCode(), $list));
        }

        $existing = collect($list['webhooks'] ?? [])->first(
            static fn (mixed $webhook): bool => is_array($webhook) && hash_equals((string) ($webhook['url'] ?? ''), $url)
        );
        $payload = [
            'url'         => $url,
            'description' => 'LONGOS transactional delivery status',
            'events'      => self::EVENTS,
            'type'        => 'transactional',
            'channel'     => 'email',
            'batched'     => false,
            'auth'        => [
                'type'  => 'bearer',
                'token' => $runtime['webhook_token'],
            ],
        ];

        if (is_array($existing) && (int) ($existing['id'] ?? 0) > 0) {
            $id       = (int) $existing['id'];
            $response = $client->put('/v3/webhooks/' . $id, ['json' => $payload]);
            if (! in_array($response->getStatusCode(), [200, 204], true)) {
                $body = json_decode((string) $response->getBody(), true);

                throw new RuntimeException($this->providerError($response->getStatusCode(), $body));
            }

            return ['id' => $id, 'created' => false, 'url' => $url];
        }

        $response = $client->post('/v3/webhooks', ['json' => $payload]);
        $body     = json_decode((string) $response->getBody(), true);
        $id       = is_array($body) ? (int) ($body['id'] ?? 0) : 0;
        if ($response->getStatusCode() !== 201 || $id <= 0) {
            throw new RuntimeException($this->providerError($response->getStatusCode(), $body));
        }

        return ['id' => $id, 'created' => true, 'url' => $url];
    }

    private function providerError(int $status, mixed $body): string
    {
        $value  = is_array($body) ? ($body['message'] ?? $body['code'] ?? '') : '';
        $reason = is_scalar($value) ? trim((string) $value) : '';
        $reason = $reason !== '' ? mb_substr($reason, 0, 300) : '服务商拒绝请求';

        return "Brevo 回执配置失败 [{$status}]: {$reason}";
    }
}
