<?php

namespace App\Mail;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Resolve the public egress address shared by the LONGOS application and its
 * private mail-relay container.
 *
 * An explicit MAIL_RELAY_IPS value always wins. Automatic discovery only
 * accepts an address when independent HTTPS reflectors reach the configured
 * quorum; a single response can therefore never rewrite public mail DNS.
 */
final class MailRelayPublicIpResolver
{
    /** @return array<int, string> */
    public function resolve(): array
    {
        return $this->resolution()['ips'];
    }

    /**
     * @return array{ips: array<int, string>, source: string, message: string}
     */
    public function resolution(): array
    {
        $configured = $this->normalizePublicIps((array) config('mail.relay.public_ips', []));
        if ($configured !== []) {
            return [
                'ips'     => $configured,
                'source'  => 'configured',
                'message' => '使用 MAIL_RELAY_IPS 中显式配置的邮件出口公网 IP。',
            ];
        }

        $identity = app(MailRelayIdentityService::class);
        if ($identity->external()) {
            try {
                $addresses = $identity->identity()['public_ips'];

                return [
                    'ips'     => $addresses,
                    'source'  => 'remote_node',
                    'message' => '使用第二台邮件出口节点经 HMAC 身份校验后报告的公网 IP：' . implode(', ', $addresses) . '。',
                ];
            } catch (Throwable $exception) {
                return [
                    'ips'     => [],
                    'source'  => 'unavailable',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if (! (bool) config('mail.relay.auto_detect_public_ips', true)) {
            return [
                'ips'     => [],
                'source'  => 'disabled',
                'message' => '邮件出口公网 IP 自动识别已关闭，且 MAIL_RELAY_IPS 为空。',
            ];
        }

        $endpoints = array_values(array_unique(array_filter(array_map(
            static fn (mixed $endpoint): string => trim((string) $endpoint),
            (array) config('mail.relay.public_ip_detection_endpoints', []),
        ), static fn (string $endpoint): bool => self::safeEndpoint($endpoint))));
        $quorum = max(2, (int) config('mail.relay.public_ip_detection_quorum', 2));
        if (count($endpoints) < $quorum) {
            return [
                'ips'     => [],
                'source'  => 'unavailable',
                'message' => '邮件出口公网 IP 自动识别源不足，未达到安全一致性要求。',
            ];
        }

        $cacheSeconds = max(60, (int) config('mail.relay.public_ip_detection_cache_seconds', 900));
        $cacheKey     = 'longos:mail-relay-public-ip:v1:' . hash('sha256', json_encode([$endpoints, $quorum]) ?: '');

        try {
            /** @var array{ips: array<int, string>, source: string, message: string} $result */
            $result = Cache::remember(
                $cacheKey,
                now()->addSeconds($cacheSeconds),
                fn (): array => $this->detect($endpoints, $quorum),
            );
        } catch (Throwable) {
            // Database-backed cache may not be ready during the first install.
            // Discovery is still safe without caching because quorum remains
            // mandatory.
            $result = $this->detect($endpoints, $quorum);
        }

        return $result;
    }

    /**
     * @param array<int, string> $endpoints
     * @return array{ips: array<int, string>, source: string, message: string}
     */
    private function detect(array $endpoints, int $quorum): array
    {
        $votes      = [];
        $responders = 0;
        $timeout    = max(1, min(10, (int) config('mail.relay.public_ip_detection_timeout', 3)));

        foreach ($endpoints as $endpoint) {
            try {
                $response = Http::accept('text/plain')
                    ->withHeaders(['User-Agent' => 'LONGOS-Mail-Relay-IP-Discovery/1.0'])
                    ->connectTimeout(min(2, $timeout))
                    ->timeout($timeout)
                    ->withOptions([
                        'allow_redirects' => false,
                        // The bundled Postfix defaults to INET_PROTOCOLS=ipv4.
                        // IPv6 remains available through an explicit
                        // MAIL_RELAY_IPS override when the relay is enabled
                        // for IPv6 and its PTR/routing are already verified.
                        'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                    ])
                    ->get($endpoint);
                if (! $response->successful() || strlen($response->body()) > 4096) {
                    continue;
                }

                $address = $this->extractAddress($response->body());
                if ($address === '') {
                    continue;
                }

                $responders++;
                $votes[$address] = ($votes[$address] ?? 0) + 1;
                if ($votes[$address] >= $quorum) {
                    return [
                        'ips'     => [$address],
                        'source'  => 'detected',
                        'message' => "已由服务器端多源一致性探测自动识别邮件出口公网 IP：{$address}。",
                    ];
                }
            } catch (Throwable) {
                // A failed reflector is expected to be replaceable by the
                // remaining independent sources. Never accept a lone answer.
            }
        }

        return [
            'ips'     => [],
            'source'  => 'unavailable',
            'message' => $responders === 0
                ? '无法连接公网 IP 自动识别服务；未发布邮件主机 A/AAAA，请检查服务器出站 HTTPS 或手工填写 MAIL_RELAY_IPS。'
                : '公网 IP 自动识别结果未达到至少两个来源一致；为避免发布错误地址，未修改邮件主机 A/AAAA，请手工填写 MAIL_RELAY_IPS。',
        ];
    }

    private function extractAddress(string $body): string
    {
        $body      = trim($body);
        $candidate = $body;
        if (preg_match('/(?:^|\R)ip=([^\r\n]+)/i', $body, $match) === 1) {
            $candidate = trim($match[1]);
        }

        return $this->normalizePublicIps([$candidate])[0] ?? '';
    }

    /** @param array<int, mixed> $values
     *  @return array<int, string>
     */
    private function normalizePublicIps(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static function (mixed $value): string {
                $packed = @inet_pton(trim((string) $value));

                return $packed === false ? '' : (inet_ntop($packed) ?: '');
            },
            $values,
        ), static fn (string $value): bool => filter_var(
            $value,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE,
        ) !== false)));
    }

    private static function safeEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['fragment']);
    }
}
