<?php

namespace App\Mail;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Performs read-only Brevo control-plane checks without sending email.
 * Credentials and provider response bodies are never logged or returned.
 */
class BrevoConnectionService
{
    private const API_BASE = 'https://api.brevo.com/v3';

    public function __construct(private readonly ?ClientInterface $client = null) {}

    /**
     * @return array{
     *   connected:bool,
     *   account_email:string,
     *   sender_domain:string,
     *   sender_status:string,
     *   sender_message:string
     * }
     */
    public function inspect(string $apiKey, int $timeout, string $sender = ''): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new RuntimeException('Brevo API Key 未配置。');
        }

        $client = $this->client ?? new Client([
            'connect_timeout' => min(8, max(3, $timeout)),
            'timeout'         => max(3, min(60, $timeout)),
            'verify'          => true,
            'http_errors'     => false,
            'allow_redirects' => false,
        ]);
        $options = [
            'http_errors'     => false,
            'allow_redirects' => false,
            'headers'         => [
                'accept'  => 'application/json',
                'api-key' => $apiKey,
            ],
        ];

        try {
            $account = $client->request('GET', self::API_BASE . '/account', $options);
        } catch (Throwable $exception) {
            throw new RuntimeException('LONGOS 无法通过 HTTPS 连接 Brevo，请检查主服务器 DNS、出站 443 和系统时间。', previous: $exception);
        }

        if ($account->getStatusCode() !== 200) {
            throw new RuntimeException($this->providerError($account));
        }

        $accountPayload = $this->json($account);
        $domain         = $this->senderDomain($sender);
        $senderStatus   = $domain === '' ? 'warning' : 'unknown';
        $senderMessage  = $domain === '' ? '请填写有效的默认发件邮箱。' : "尚未验证发件域 {$domain}。";

        if ($domain !== '') {
            try {
                $domainResponse = $client->request(
                    'GET',
                    self::API_BASE . '/senders/domains/' . rawurlencode($domain),
                    $options,
                );
                $domainPayload = $this->json($domainResponse);
                $verified      = $domainResponse->getStatusCode() === 200 && (bool) ($domainPayload['verified'] ?? false);
                $authenticated = $domainResponse->getStatusCode() === 200 && (bool) ($domainPayload['authenticated'] ?? false);
                $ready         = $verified                                && $authenticated;
                $senderStatus  = $ready ? 'ok' : 'warning';
                $senderMessage = $ready
                    ? "Brevo 已验证并认证发件域 {$domain}。"
                    : "Brevo API 可用，但发件域 {$domain} 尚未完成验证和认证。";
            } catch (Throwable) {
                // Account authentication is the connection boundary. A domain
                // lookup failure remains actionable but must not hide that the
                // API key itself is valid.
                $senderStatus  = 'warning';
                $senderMessage = "Brevo API 可用，但无法读取发件域 {$domain} 的验证状态。";
            }

            // Brevo also permits a specifically verified sender address even
            // when the whole domain has not been authenticated. Recognize that
            // supported setup instead of reporting a false configuration
            // failure to the administrator.
            if ($senderStatus !== 'ok' && $this->activeSenderExists($client, $options, $sender, $domain)) {
                $senderStatus  = 'ok';
                $senderMessage = "Brevo 已验证发件人 {$sender}；建议继续完成域名认证以提高送达率。";
            }
        }

        return [
            'connected'      => true,
            'account_email'  => filter_var($accountPayload['email'] ?? '', FILTER_VALIDATE_EMAIL)
                ? (string) $accountPayload['email']
                : '',
            'sender_domain'  => $domain,
            'sender_status'  => $senderStatus,
            'sender_message' => $senderMessage,
        ];
    }

    private function senderDomain(string $sender): string
    {
        $sender = strtolower(trim($sender));
        if (! filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            return '';
        }

        return (string) substr(strrchr($sender, '@') ?: '', 1);
    }

    /** @param array<string, mixed> $options */
    private function activeSenderExists(ClientInterface $client, array $options, string $sender, string $domain): bool
    {
        try {
            $response = $client->request('GET', self::API_BASE . '/senders', $options + [
                'query' => ['domain' => $domain],
            ]);
            if ($response->getStatusCode() !== 200) {
                return false;
            }

            $senders = $this->json($response)['senders'] ?? [];
            if (! is_array($senders)) {
                return false;
            }

            $sender = strtolower($sender);

            foreach ($senders as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }
                $email = is_scalar($candidate['email'] ?? null)
                    ? strtolower(trim((string) $candidate['email']))
                    : '';
                if ($email === $sender && ($candidate['active'] ?? false) === true) {
                    return true;
                }
            }
        } catch (Throwable) {
            // Domain verification remains the primary readiness signal. The
            // sender-list fallback is advisory and must not break API checks.
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true);

        return is_array($payload) ? $payload : [];
    }

    private function providerError(ResponseInterface $response): string
    {
        $status  = $response->getStatusCode();
        $payload = $this->json($response);
        $value   = $payload['message'] ?? $payload['code'] ?? '';
        $reason  = is_scalar($value) ? trim((string) $value) : '';
        $reason  = $reason !== '' ? mb_substr(strip_tags($reason), 0, 200) : '服务商拒绝请求';
        $help    = match ($status) {
            401, 403 => '请重新生成 API v3 Key，并检查 Brevo 账户的 IP 安全限制。',
            429     => 'Brevo API 请求过于频繁，请稍后重试。',
            default => '请在 Brevo 后台检查账户状态和 API 权限。',
        };

        return "Brevo 连接验证失败 [{$status}]：{$reason}。{$help}";
    }
}
