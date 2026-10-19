<?php

namespace App\Mail;

use GuzzleHttp\Client;
use Throwable;

class MailDiagnosticsService
{
    /** @return array{status: string, checks: array<int, array{key: string, status: string, message: string}>} */
    public function diagnose(): array
    {
        $checks = [];
        $engine = strtolower(trim((string) system_setting('base.mail_engine', config('mail.default', ''))));
        $this->add($checks, 'engine', $engine !== '' ? 'ok' : 'error', $engine !== '' ? "当前邮件引擎：{$engine}" : '尚未启用邮件引擎。');

        $profile = app(MailSenderProfileService::class)->resolve();
        $this->add(
            $checks,
            'sender',
            filter_var($profile['address'], FILTER_VALIDATE_EMAIL) ? 'ok' : 'error',
            filter_var($profile['address'], FILTER_VALIDATE_EMAIL) ? "默认发件人：{$profile['address']}" : '默认发件邮箱无效。',
        );

        if ($engine === 'self_hosted') {
            $this->diagnoseSelfHosted($checks, $profile['address']);
        }

        $severity = ['ok' => 0, 'warning' => 1, 'error' => 2];
        $status   = 'ok';
        foreach ($checks as $check) {
            if ($severity[$check['status']] > $severity[$status]) {
                $status = $check['status'];
            }
        }

        return ['status' => $status, 'checks' => $checks];
    }

    /** @param array<int, array{key: string, status: string, message: string}> $checks */
    private function diagnoseSelfHosted(array &$checks, string $sender): void
    {
        $enabled = (bool) config('mail.relay.enabled');
        $this->add($checks, 'relay_enabled', $enabled ? 'ok' : 'error', $enabled ? '内置邮件中继已启用。' : 'LONGOS_MAIL_RELAY_ENABLED 未启用。');

        $credentialsReady = (bool) config('mail.relay.longos_client_configured')
            && (bool) config('mail.relay.cloud_mail_client_configured')
            && (bool) config('mail.relay.client_secrets_distinct');
        $this->add($checks, 'credentials', $credentialsReady ? 'ok' : 'error', $credentialsReady ? 'LONGOS 站内投递和应用网关的内部 HMAC 密钥已分离配置。' : '内部中继密钥未完整配置，或站内投递与应用网关使用了相同密钥。');

        try {
            app(MailSenderProfileService::class)->assertAuthorized($sender);
            $this->add($checks, 'sender_authorization', 'ok', '发件身份已通过租户与签名域授权检查。');
        } catch (Throwable $exception) {
            $this->add($checks, 'sender_authorization', 'error', $exception->getMessage());
        }

        $endpoint = rtrim(trim((string) config('mail.mailers.self_hosted.endpoint')), '/');
        if (! $this->safeEndpoint($endpoint)) {
            $this->add($checks, 'relay_health', 'error', '内置中继端点无效，仅允许 HTTPS 或私有 mail-relay/localhost HTTP。');
        } else {
            try {
                $response = (new Client([
                    'timeout'         => 5,
                    'connect_timeout' => 3,
                    'http_errors'     => false,
                    'allow_redirects' => false,
                    'verify'          => true,
                ]))->get($endpoint . '/health', ['headers' => ['Accept' => 'application/json']]);
                $payload = json_decode((string) $response->getBody(), true, 8);
                $healthy = $response->getStatusCode() === 200
                    && is_array($payload)
                    && ($payload['status'] ?? null) === 'ok';
                $this->add($checks, 'relay_health', $healthy ? 'ok' : 'error', $healthy ? '中继 API、Postfix 和去重回执存储运行正常。' : '中继健康检查返回异常状态。');
            } catch (Throwable) {
                $this->add($checks, 'relay_health', 'error', '无法连接内置中继 API，请检查 mail-relay 容器与 Docker 网络。');
            }
        }

        $hostname = $this->normalizeDomain(config('mail.relay.hostname'));
        if (! $this->validDomain($hostname)) {
            $this->add($checks, 'mail_hostname', 'error', 'MAIL_RELAY_HOSTNAME 未配置或格式无效。');
        } else {
            $addresses = $this->addressRecords($hostname);
            $this->add($checks, 'mail_hostname', $addresses !== [] ? 'ok' : 'error', $addresses !== [] ? "{$hostname} 已解析到 " . implode(', ', $addresses) : "{$hostname} 没有可用的 A/AAAA 记录。");
        }

        $selector = strtolower(trim((string) config('mail.relay.dkim_selector', 'mail2026')));
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $selector)) {
            $this->add($checks, 'dkim_selector', 'error', 'MAIL_RELAY_DKIM_SELECTOR 格式无效。');

            return;
        }

        $domains = $this->configuredDomains();
        if ($domains === []) {
            $this->add($checks, 'signing_domains', 'error', 'MAIL_RELAY_DOMAINS 未配置任何 DKIM 签名域。');

            return;
        }

        foreach ($domains as $domain) {
            if (! $this->validDomain($domain)) {
                $this->add($checks, "domain:{$domain}", 'error', "签名域 {$domain} 格式无效。");

                continue;
            }

            $spf = $this->txtRecords($domain);
            $this->add($checks, "spf:{$domain}", $this->containsPrefix($spf, 'v=spf1') ? 'ok' : 'error', $this->containsPrefix($spf, 'v=spf1') ? "{$domain} 已发布 SPF。" : "{$domain} 缺少 SPF TXT 记录。");

            $dkimHost  = "{$selector}._domainkey.{$domain}";
            $dkim      = $this->txtRecords($dkimHost);
            $validDkim = $this->containsPrefix($dkim, 'v=dkim1') && preg_match('/(?:^|;)\s*p\s*=\s*[A-Za-z0-9+\/=]+/i', implode(';', $dkim));
            $this->add($checks, "dkim:{$domain}", $validDkim ? 'ok' : 'error', $validDkim ? "{$domain} 的 DKIM 记录有效。" : ($dkim === [] ? "{$dkimHost} 缺少 DKIM TXT 记录。" : "{$dkimHost} 的 DKIM TXT 记录格式无效。"));

            $dmarc = $this->txtRecords("_dmarc.{$domain}");
            $this->add($checks, "dmarc:{$domain}", $this->containsPrefix($dmarc, 'v=dmarc1') ? 'ok' : 'warning', $this->containsPrefix($dmarc, 'v=dmarc1') ? "{$domain} 已发布 DMARC。" : "{$domain} 尚未发布 DMARC，建议先使用 p=none 观察。");
        }
    }

    private function safeEndpoint(string $endpoint): bool
    {
        if (! filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($endpoint, PHP_URL_SCHEME));
        $host   = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['mail-relay', '127.0.0.1', 'localhost'], true));
    }

    /** @return array<int, string> */
    private function configuredDomains(): array
    {
        $domains = config('mail.relay.domains', '');

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $domain): string => $this->normalizeDomain($domain),
            is_array($domains) ? $domains : explode(',', (string) $domains),
        ))));
    }

    private function normalizeDomain(mixed $domain): string
    {
        return strtolower(trim((string) $domain, " .\t\n\r\0\x0B"));
    }

    private function validDomain(string $domain): bool
    {
        return $domain !== '' && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /** @return array<int, string> */
    private function txtRecords(string $host): array
    {
        if ($host === '') {
            return [];
        }
        if (! function_exists('dns_get_record')) {
            return [];
        }
        $records = @dns_get_record($host, DNS_TXT);
        if (! is_array($records)) {
            return [];
        }

        return array_values(array_filter(array_map(static function (array $record): string {
            if (isset($record['entries']) && is_array($record['entries'])) {
                return implode('', $record['entries']);
            }

            return (string) ($record['txt'] ?? '');
        }, $records)));
    }

    /** @return array<int, string> */
    private function addressRecords(string $host): array
    {
        if ($host === '') {
            return [];
        }
        if (! function_exists('dns_get_record')) {
            return [];
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (array $record): string => (string) ($record['ip'] ?? $record['ipv6'] ?? ''),
            $records,
        ))));
    }

    /** @param array<int, string> $records */
    private function containsPrefix(array $records, string $prefix): bool
    {
        foreach ($records as $record) {
            if (str_starts_with(strtolower(trim($record)), $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array{key: string, status: string, message: string}> $checks */
    private function add(array &$checks, string $key, string $status, string $message): void
    {
        $checks[] = compact('key', 'status', 'message');
    }
}
