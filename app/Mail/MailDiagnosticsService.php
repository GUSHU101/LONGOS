<?php

namespace App\Mail;

use GuzzleHttp\Client;
use Throwable;

class MailDiagnosticsService
{
    /**
     * @return array{
     *   status: string,
     *   checks: array<int, array{key: string, status: string, message: string}>,
     *   dns_records: array<int, array{type: string, name: string, value: string, mode: string, available: bool}>
     * }
     */
    public function diagnose(): array
    {
        $checks     = [];
        $dnsRecords = [];
        $engine     = strtolower(trim((string) system_setting('base.mail_engine', config('mail.default', ''))));
        $this->add($checks, 'engine', $engine !== '' ? 'ok' : 'error', $engine !== '' ? "当前邮件引擎：{$engine}" : '尚未启用邮件引擎。');

        $profile = app(MailSenderProfileService::class)->resolve();
        $this->add(
            $checks,
            'sender',
            filter_var($profile['address'], FILTER_VALIDATE_EMAIL) ? 'ok' : 'error',
            filter_var($profile['address'], FILTER_VALIDATE_EMAIL) ? "默认发件人：{$profile['address']}" : '默认发件邮箱无效。',
        );

        if ($engine === 'self_hosted') {
            $runtime = app(MailRelayRuntimeSettings::class)->status();
            $this->add(
                $checks,
                'runtime_topology',
                $runtime['source'] === 'invalid' ? 'warning' : 'ok',
                match ($runtime['source']) {
                    'control_panel' => '当前使用控制站后台保存的全平台邮件出口配置。',
                    'invalid'       => '后台邮件出口配置不可用，已安全回退 Coolify/Compose 默认值：' . ($runtime['error'] ?: '未知格式错误'),
                    default         => '当前使用 Coolify/Compose 环境变量中的邮件出口默认配置。',
                },
            );

            try {
                $dnsRecords = app(CloudflareMailDnsService::class)->expectedRecords();
            } catch (Throwable $exception) {
                $this->add($checks, 'dns_record_preview', 'error', $exception->getMessage());
            }

            $this->diagnoseSelfHosted($checks, $profile['address'], $dnsRecords);
        }

        $severity = ['ok' => 0, 'warning' => 1, 'error' => 2];
        $status   = 'ok';
        foreach ($checks as $check) {
            if ($severity[$check['status']] > $severity[$status]) {
                $status = $check['status'];
            }
        }

        return ['status' => $status, 'checks' => $checks, 'dns_records' => $dnsRecords];
    }

    /** @param array<int, array{key: string, status: string, message: string}> $checks */
    private function diagnoseSelfHosted(array &$checks, string $sender, array $expectedDnsRecords): void
    {
        $enabled  = (bool) config('mail.relay.enabled');
        $external = app(MailRelayIdentityService::class)->external();
        $this->add(
            $checks,
            'relay_enabled',
            $enabled ? 'ok' : 'error',
            $enabled
                ? ($external ? '独立邮件出口节点已启用；LONGOS 与 Cloud Mail 仍运行在主服务器。' : '同机内置邮件中继已启用。')
                : 'LONGOS_MAIL_RELAY_ENABLED 未启用。',
        );

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
            $this->add($checks, 'relay_health', 'error', '邮件中继端点无效：外置节点必须使用 HTTPS，同机节点仅允许私有 mail-relay/localhost HTTP。');
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
                $components = is_array($payload['components'] ?? null) ? $payload['components'] : [];
                $degraded   = collect($components)
                    ->filter(static fn (mixed $status): bool => $status !== 'ok')
                    ->keys()
                    ->map(static fn (mixed $name): string => match ((string) $name) {
                        'postfix'      => 'Postfix',
                        'opendkim'     => 'OpenDKIM',
                        'queue'        => '邮件队列',
                        'receiptStore' => '去重回执存储',
                        default        => (string) $name,
                    })
                    ->values()
                    ->all();
                $this->add(
                    $checks,
                    'relay_health',
                    $healthy ? 'ok' : 'error',
                    $healthy
                        ? ($external
                            ? '第二台服务器的中继 API、Postfix、OpenDKIM 和去重回执存储运行正常。'
                            : '同机中继 API、Postfix、OpenDKIM 和去重回执存储运行正常。')
                        : ($degraded === [] ? '中继健康检查返回异常状态。' : '中继组件异常：' . implode('、', $degraded) . '。'),
                );

                if (is_array($payload)) {
                    $queueDepth = filter_var($payload['queueDepth'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                    $queueLimit = filter_var($payload['queueLimit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($queueDepth !== false && $queueLimit !== false) {
                        // A healthy Postfix process only proves that the node can
                        // accept mail. Any queued message is still awaiting its
                        // recipient MX and must not be presented as delivered.
                        $queueStatus = $queueDepth > $queueLimit ? 'error' : ($queueDepth > 0 ? 'warning' : 'ok');
                        $queueHelp   = match ($queueStatus) {
                            'error'   => '队列已超过安全上限，中继节点应停止接收新任务并立即处理积压。',
                            'warning' => '这些邮件尚未完成公网投递；若数分钟后仍未归零，请检查节点出站 TCP 25、Postfix 日志和收件方拒收原因。',
                            default   => '当前没有等待投递的邮件。',
                        };
                        $this->add(
                            $checks,
                            'relay_queue',
                            $queueStatus,
                            "Postfix 队列当前 {$queueDepth} 封，安全上限 {$queueLimit} 封。{$queueHelp}",
                        );
                    }

                    $uncertain = filter_var($payload['uncertainRequests'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                    $records   = filter_var($payload['idempotencyRecords'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                    $capacity  = filter_var($payload['idempotencyCapacity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($uncertain !== false && $records !== false && $capacity !== false) {
                        $receiptStatus = $uncertain > 0 || ($records / $capacity) >= 0.8 ? 'warning' : 'ok';
                        $this->add(
                            $checks,
                            'relay_receipts',
                            $receiptStatus,
                            "去重回执 {$records}/{$capacity}，状态不确定的投递 {$uncertain} 封。"
                                . ($uncertain > 0 ? '为防止重复发信，这些投递不会被自动重复提交。' : ''),
                        );
                    }
                }
            } catch (Throwable) {
                $this->add(
                    $checks,
                    'relay_health',
                    'error',
                    $external
                        ? '无法连接第二台服务器的中继 API，请检查 HTTPS 反向代理、证书、防火墙和 mail-relay 容器。'
                        : '无法连接同机中继 API，请检查 mail-relay 容器与 Docker 网络。',
                );
            }
        }

        $hostname = $this->normalizeDomain(config('mail.relay.hostname'));
        if (! $this->validDomain($hostname)) {
            $this->add($checks, 'mail_hostname', 'error', 'MAIL_RELAY_HOSTNAME 未配置或格式无效。');
        } else {
            $addresses  = $this->addressRecords($hostname);
            $resolution = app(MailRelayPublicIpResolver::class)->resolution();
            $expected   = $resolution['ips'];
            if ($addresses === []) {
                $this->add($checks, 'mail_hostname', 'error', "{$hostname} 没有可用的 A/AAAA 记录。");
            } elseif ($expected === []) {
                $this->add(
                    $checks,
                    'mail_hostname',
                    'warning',
                    "{$hostname} 已解析到 " . implode(', ', $addresses) . '，但' . $resolution['message'],
                );
            } else {
                $matches = array_diff($expected, $addresses) === [] && array_diff($addresses, $expected) === [];
                $source  = match ($resolution['source']) {
                    'detected'    => '（主服务器自动识别）',
                    'remote_node' => '（第二台邮件出口节点签名报告）',
                    default       => '（MAIL_RELAY_IPS）',
                };
                $this->add(
                    $checks,
                    'mail_hostname',
                    $matches ? 'ok' : 'error',
                    $matches
                        ? "{$hostname} 已通过 DNS-only A/AAAA 解析到邮件服务器公网 IP{$source}：" . implode(', ', $addresses)
                        : "{$hostname} 当前解析为 " . implode(', ', $addresses) . "，与邮件出口公网 IP{$source}（" . implode(', ', $expected) . '）不一致；请重新运行 Cloud Mail 部署向导并确保为灰云 DNS-only。',
                );
            }

            if ($expected !== []) {
                $ptrFailures = [];
                $ptrWarnings = [];
                $ptrResolver = app(MailReverseDnsResolver::class);
                foreach ($expected as $address) {
                    $lookup  = $ptrResolver->resolve($address);
                    $targets = $lookup['targets'];
                    if ($lookup['state'] === 'indeterminate') {
                        $ptrWarnings[] = "{$address}（{$lookup['message']}。这不能证明 PTR 值错误，但公网收件服务器目前也无法可靠读取；请联系 IP 服务商修复反向 DNS 区域的委派或权威服务器发布。）";
                    } elseif ($lookup['state'] === 'unavailable') {
                        $ptrWarnings[] = "{$address}（{$lookup['message']}）";
                    } elseif (! in_array($hostname, $targets, true)) {
                        $ptrFailures[] = "{$address}（{$lookup['message']}）";
                    } elseif ($lookup['state'] === 'partial') {
                        $ptrWarnings[] = "{$address}（已包含 {$hostname}，但{$lookup['message']}）";
                    } elseif (count($targets) > 1) {
                        $ptrWarnings[] = "{$address}（PTR 已包含 {$hostname}，但还返回其他目标：" . implode(', ', array_diff($targets, [$hostname])) . '）';
                    }
                }

                $status  = $ptrFailures !== [] ? 'error' : ($ptrWarnings !== [] ? 'warning' : 'ok');
                $message = match ($status) {
                    'ok'      => '所有邮件服务器公网 IP 的 PTR/rDNS 均已由独立公共解析器确认反向指向 ' . $hostname . '。',
                    'warning' => 'PTR/rDNS 尚未完成公网验收：' . implode('；', $ptrWarnings),
                    default   => 'PTR/rDNS 未与 MAIL_RELAY_HOSTNAME 对齐：' . implode('；', $ptrFailures)
                        . ($external
                            ? '。请在第二台邮件出口服务器的 IP 服务商后台设置；Cloudflare 代理和普通反向代理都不能代替 PTR。'
                            : '。PTR 只能在当前邮件服务器/IP 服务商处设置；Cloudflare 代理不能代替 PTR。'),
                };
                $this->add(
                    $checks,
                    'reverse_dns',
                    $status,
                    $message,
                );
            } else {
                $this->add($checks, 'reverse_dns', 'warning', $resolution['message'] . ' 无法自动验收 PTR/rDNS。');
            }
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

            $spf      = $this->txtRecords($domain);
            $spfCount = $this->prefixCount($spf, 'v=spf1');
            $spfError = $spfCount === 1 ? $this->spfValidationError($spf, 'a:' . $hostname) : null;
            $this->add(
                $checks,
                "spf:{$domain}",
                $spfCount === 1 && $spfError === null ? 'ok' : 'error',
                $spfCount === 1
                    ? ($spfError ?? "{$domain} 已发布唯一 SPF，且直接 DNS 查询机制未超过 10 次。")
                    : ($spfCount === 0 ? "{$domain} 缺少 SPF TXT 记录。" : "{$domain} 存在 {$spfCount} 条 SPF 记录，收件服务商会判定 PermError，请合并为一条。"),
            );

            $dkimHost   = "{$selector}._domainkey.{$domain}";
            $dkim       = $this->txtRecords($dkimHost);
            $dkimCount  = $this->prefixCount($dkim, 'v=dkim1');
            $currentKey = $dkimCount === 1 ? $this->dkimPublicKey($dkim[0] ?? '') : '';
            $expected   = collect($expectedDnsRecords)->first(
                static fn (array $record): bool => strtolower((string) ($record['type'] ?? '')) === 'txt'
                    && strtolower((string) ($record['name'] ?? ''))                             === $dkimHost
                    && (bool) ($record['available'] ?? false),
            );
            $expectedKey = is_array($expected) ? $this->dkimPublicKey((string) ($expected['value'] ?? '')) : '';
            $validDkim   = $currentKey !== '' && ($expectedKey === '' || hash_equals($expectedKey, $currentKey));
            $this->add(
                $checks,
                "dkim:{$domain}",
                $validDkim ? 'ok' : 'error',
                $validDkim
                    ? "{$domain} 的 DKIM 公钥与当前邮件中继一致。"
                    : ($dkim === []
                        ? "{$dkimHost} 缺少 DKIM TXT 记录。"
                        : ($dkimCount > 1
                            ? "{$dkimHost} 存在多条 DKIM 记录，请只保留当前公钥。"
                            : ($currentKey === ''
                                ? "{$dkimHost} 的 DKIM TXT 记录格式无效。"
                                : "{$dkimHost} 仍是旧公钥，与当前邮件中继不一致；请运行一键配置。"))),
            );

            $dmarc      = $this->txtRecords("_dmarc.{$domain}");
            $dmarcCount = $this->prefixCount($dmarc, 'v=dmarc1');
            $validDmarc = $dmarcCount                                                                          === 1
                && preg_match('/(?:^|;)\s*p\s*=\s*(?:none|quarantine|reject)(?:\s*;|\s*$)/i', $dmarc[0] ?? '') === 1;
            $this->add(
                $checks,
                "dmarc:{$domain}",
                $validDmarc ? 'ok' : ($dmarcCount === 0 ? 'warning' : 'error'),
                $validDmarc
                    ? "{$domain} 已发布唯一 DMARC。"
                    : ($dmarcCount === 0
                        ? "{$domain} 尚未发布 DMARC，建议先使用 p=none 观察。"
                        : ($dmarcCount > 1
                            ? "_dmarc.{$domain} 存在 {$dmarcCount} 条 DMARC 记录，请合并为一条。"
                            : "_dmarc.{$domain} 缺少有效的 p=none/quarantine/reject 策略。")),
            );
        }
    }

    private function safeEndpoint(string $endpoint): bool
    {
        if (! filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($endpoint, PHP_URL_SCHEME));
        $host   = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        if (parse_url($endpoint, PHP_URL_USER)        !== null
            || parse_url($endpoint, PHP_URL_PASS)     !== null
            || parse_url($endpoint, PHP_URL_QUERY)    !== null
            || parse_url($endpoint, PHP_URL_FRAGMENT) !== null) {
            return false;
        }

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
        return str_contains($domain, '.')
            && filter_var($domain, FILTER_VALIDATE_IP) === false
            && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
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
            static fn (array $record): string => self::normalizeIp((string) ($record['ip'] ?? $record['ipv6'] ?? '')),
            $records,
        ))));
    }

    private static function normalizeIp(string $address): string
    {
        $packed = @inet_pton(trim($address));

        return $packed === false ? '' : (inet_ntop($packed) ?: '');
    }

    /** @param array<int, string> $records */
    private function prefixCount(array $records, string $prefix): int
    {
        return count(array_filter(
            $records,
            static fn (string $record): bool => preg_match(
                '/^' . preg_quote($prefix, '/') . '(?:[\s;]|$)/i',
                trim($record),
            ) === 1,
        ));
    }

    /** @param array<int, string> $records */
    private function spfValidationError(array $records, string $requiredMechanism): ?string
    {
        $policy = collect($records)->first(
            static fn (string $record): bool => preg_match('/^v=spf1(?:\s|$)/i', trim($record)) === 1,
        );
        if (! is_string($policy)) {
            return 'SPF 版本标记格式无效。';
        }

        $tokens = preg_split('/\s+/', trim($policy)) ?: [];
        array_shift($tokens);
        if (array_filter($tokens, static fn (string $token): bool => preg_match('/^[+]?all$/i', $token) === 1) !== []) {
            return 'SPF 包含 +all，会授权任意服务器冒用该域发信。';
        }
        $requiredPattern = '/^(?:\+)?' . preg_quote($requiredMechanism, '/') . '(?:\/\d+)?(?:\/\/\d+)?$/i';
        if (array_filter($tokens, static fn (string $token): bool => preg_match($requiredPattern, $token) === 1) === []) {
            return "SPF 尚未授权 LONGOS 邮件主机（缺少 {$requiredMechanism}）。";
        }

        $lookups = count(array_filter(
            $tokens,
            static fn (string $token): bool => preg_match(
                '/^[+\-~?]?(?:include:|a(?::|\/|$)|mx(?::|\/|$)|ptr(?::|$)|exists:|redirect=)/i',
                $token,
            ) === 1,
        ));

        return $lookups > 10
            ? "SPF 至少需要 {$lookups} 次 DNS 查询，超过 RFC 规定的 10 次上限。"
            : null;
    }

    private function dkimPublicKey(string $record): string
    {
        return preg_match('/(?:^|;)\s*p\s*=\s*([A-Za-z0-9+\/=]+)/i', $record, $match) === 1
            ? $match[1]
            : '';
    }

    /** @param array<int, array{key: string, status: string, message: string}> $checks */
    private function add(array &$checks, string $key, string $status, string $message): void
    {
        $checks[] = compact('key', 'status', 'message');
    }
}
