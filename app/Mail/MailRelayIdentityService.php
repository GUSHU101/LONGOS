<?php

namespace App\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Read the public identity of a dedicated outbound mail node.
 *
 * LONGOS and Cloud Mail may remain on the primary server while a second VPS
 * owns the SMTP egress IP and PTR. The remote node exposes only public DNS
 * material through a replay-protected HMAC request; private DKIM keys never
 * leave that node.
 */
final class MailRelayIdentityService
{
    /** @var array{hostname: string, public_ips: array<int, string>, dkim_selector: string, signing_domains: array<int, string>, records: array<string, string>}|null */
    private ?array $identity = null;

    public function __construct(private readonly ?Factory $http = null) {}

    public function external(): bool
    {
        $host = strtolower((string) parse_url($this->endpoint(), PHP_URL_HOST));

        return $host !== '' && ! in_array($host, ['mail-relay', '127.0.0.1', 'localhost'], true);
    }

    /**
     * @return array{hostname: string, public_ips: array<int, string>, dkim_selector: string, signing_domains: array<int, string>, records: array<string, string>}
     */
    public function identity(): array
    {
        if (! $this->external()) {
            throw new RuntimeException('当前使用同机内置邮件中继，不需要读取远端节点身份。');
        }
        if ($this->identity !== null) {
            return $this->identity;
        }

        $endpoint = $this->endpoint();
        $secret   = (string) config('mail.mailers.self_hosted.secret', '');
        if (! $this->safeExternalEndpoint($endpoint) || strlen($secret) < 32) {
            throw new RuntimeException('外置邮件节点必须使用无凭据、无查询参数的 HTTPS 地址，并配置独立 LONGOS HMAC 密钥。');
        }

        $timestamp = (string) time();
        $nonce     = (string) Str::uuid();
        $signature = hash_hmac('sha256', "{$timestamp}.{$nonce}.", $secret);

        try {
            $response = ($this->http ?? new Factory)->acceptJson()
                ->connectTimeout(5)
                ->timeout(max(5, min(30, (int) config('mail.mailers.self_hosted.timeout', 30))))
                ->withoutRedirecting()
                ->withHeaders([
                    'X-Relay-Client'    => 'longos',
                    'X-Relay-Timestamp' => $timestamp,
                    'X-Relay-Nonce'     => $nonce,
                    'X-Relay-Signature' => $signature,
                ])
                ->get($endpoint . '/v1/identity');
        } catch (ConnectionException $exception) {
            throw new RuntimeException('无法连接外置邮件节点身份接口，请检查第二台服务器的 HTTPS 反向代理和防火墙。', previous: $exception);
        }

        if (! $response->successful()) {
            $message = trim((string) data_get($response->json(), 'message', ''));

            throw new RuntimeException('外置邮件节点拒绝身份校验'
                . ($message !== '' ? "：{$message}" : "（HTTP {$response->status()}）"));
        }
        if (strlen($response->body()) > 131072) {
            throw new RuntimeException('外置邮件节点身份响应超过安全上限。');
        }

        $payload = $response->json();
        if (! is_array($payload) || (int) ($payload['schema'] ?? 0) !== 1 || ($payload['status'] ?? null) !== 'ok') {
            throw new RuntimeException('外置邮件节点返回了不受支持的身份清单。');
        }

        $hostname = $this->domain($payload['hostname'] ?? null);
        $expected = $this->domain(config('mail.relay.hostname'));
        if ($hostname === '' || $expected === '' || ! hash_equals($expected, $hostname)) {
            throw new RuntimeException('外置邮件节点的 MAIL_HOSTNAME 与 LONGOS 的 MAIL_RELAY_HOSTNAME 不一致。');
        }

        $selector         = strtolower(trim((string) ($payload['dkimSelector'] ?? '')));
        $expectedSelector = strtolower(trim((string) config('mail.relay.dkim_selector', 'mail2026')));
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D', $selector) || ! hash_equals($expectedSelector, $selector)) {
            throw new RuntimeException('外置邮件节点的 DKIM_SELECTOR 与 LONGOS 的 MAIL_RELAY_DKIM_SELECTOR 不一致。');
        }

        $domains = array_values(array_unique(array_filter(array_map(
            fn (mixed $value): string => $this->domain($value),
            is_array($payload['signingDomains'] ?? null) ? $payload['signingDomains'] : [],
        ))));
        $requiredDomains = $this->configuredDomains();
        if ($requiredDomains === []
            || array_diff($requiredDomains, $domains) !== []
            || array_diff($domains, $requiredDomains) !== []) {
            throw new RuntimeException('外置邮件节点的 MAIL_DOMAINS 与 LONGOS 的 MAIL_RELAY_DOMAINS 不一致。');
        }

        $publicIps = $this->publicIps(is_array($payload['publicIps'] ?? null) ? $payload['publicIps'] : []);
        if ($publicIps === []) {
            throw new RuntimeException('外置邮件节点未配置 MAIL_PUBLIC_IPS，无法安全发布 A/AAAA 或验收 PTR/rDNS。');
        }
        $configuredIps = $this->publicIps((array) config('mail.relay.public_ips', []));
        if ($configuredIps !== []
            && (array_diff($configuredIps, $publicIps) !== [] || array_diff($publicIps, $configuredIps) !== [])) {
            throw new RuntimeException('外置邮件节点的 MAIL_PUBLIC_IPS 与 LONGOS 的 MAIL_RELAY_IPS 不一致。');
        }

        $records = [];
        $items   = is_array($payload['records'] ?? null) ? $payload['records'] : [];
        if ($items === [] || count($items) > 100) {
            throw new RuntimeException('外置邮件节点未返回完整的 DKIM 公钥清单。');
        }
        foreach ($items as $record) {
            if (! is_array($record) || strtoupper((string) ($record['type'] ?? '')) !== 'TXT') {
                throw new RuntimeException('外置邮件节点身份清单包含无效记录。');
            }
            $name  = strtolower(trim((string) ($record['name'] ?? ''), " .\t\n\r\0\x0B"));
            $value = trim((string) ($record['value'] ?? ''));
            if (strlen($value) > 4096 || preg_match('/[\r\n]/', $value)
                                      || preg_match('/^v=DKIM1;.*(?:^|;\s*)p=[A-Za-z0-9+\/=]+/i', $value) !== 1) {
                throw new RuntimeException('外置邮件节点返回了格式无效的 DKIM 公钥。');
            }
            $matchedDomain = collect($requiredDomains)->first(
                static fn (string $domain): bool => $name === "{$selector}._domainkey.{$domain}",
            );
            if (! is_string($matchedDomain) || isset($records[$matchedDomain])) {
                throw new RuntimeException('外置邮件节点返回了越界或重复的 DKIM 记录。');
            }
            $records[$matchedDomain] = $value;
        }
        if (array_diff($requiredDomains, array_keys($records)) !== []) {
            throw new RuntimeException('外置邮件节点缺少 LONGOS 所需的 DKIM 公钥。');
        }

        return $this->identity = [
            'hostname'        => $hostname,
            'public_ips'      => $publicIps,
            'dkim_selector'   => $selector,
            'signing_domains' => $domains,
            'records'         => $records,
        ];
    }

    public function dkimValue(string $domain, string $selector): string
    {
        $identity = $this->identity();
        if (! hash_equals($identity['dkim_selector'], strtolower(trim($selector)))) {
            throw new RuntimeException('外置邮件节点 DKIM selector 不一致。');
        }

        return $identity['records'][$this->domain($domain)] ?? '';
    }

    private function endpoint(): string
    {
        return rtrim(trim((string) config('mail.mailers.self_hosted.endpoint', '')), '/');
    }

    private function safeExternalEndpoint(string $endpoint): bool
    {
        if (! filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($endpoint);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }

    /** @return array<int, string> */
    private function configuredDomains(): array
    {
        $domains = config('mail.relay.domains', '');

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): string => $this->domain($value),
            is_array($domains) ? $domains : explode(',', (string) $domains),
        ))));
    }

    private function domain(mixed $value): string
    {
        $domain = strtolower(trim((string) $value, " .\t\n\r\0\x0B"));

        return str_contains($domain, '.')
            && filter_var($domain, FILTER_VALIDATE_IP) === false
            && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
                ? $domain
                : '';
    }

    /** @param array<int, mixed> $values
     *  @return array<int, string>
     */
    private function publicIps(array $values): array
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
}
