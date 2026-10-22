<?php

namespace App\Mail;

use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Resolve mail-server PTR records through independent public resolvers.
 *
 * PHP's dns_get_record() deliberately exposes very little resolver state: an
 * empty answer, NXDOMAIN, SERVFAIL and a refused/lame delegation can all look
 * identical to callers. Public DNS-over-HTTPS responses include the DNS RCODE,
 * allowing the admin health check to distinguish a missing record from a
 * broken reverse-zone authority. The container resolver remains a fallback so
 * diagnostics still work when the external checking services are unavailable.
 */
class MailReverseDnsResolver
{
    /** @var array<string, string> */
    private array $endpoints;

    private int $timeout;

    /**
     * @param array<string, string>|null $endpoints
     */
    public function __construct(private readonly Factory $http, ?array $endpoints = null, ?int $timeout = null)
    {
        $this->endpoints = $endpoints ?? (array) config('mail.relay.ptr_doh_endpoints', []);
        $this->timeout   = max(1, min(10, $timeout ?? (int) config('mail.relay.ptr_doh_timeout', 4)));
    }

    /**
     * @return array{state: string, targets: array<int, string>, message: string}
     */
    public function resolve(string $address): array
    {
        $reverse = self::reverseDnsName($address);
        if ($reverse === '') {
            return $this->result('invalid', [], '邮件出口 IP 格式无效，无法执行 PTR 查询。');
        }

        $answers     = [];
        $missing     = [];
        $errors      = [];
        $unavailable = [];

        $safeEndpoints = $this->safeEndpoints();
        foreach ($safeEndpoints as $name => $endpoint) {
            $lookup  = $this->lookupProvider($name, $endpoint, $reverse);
            $summary = "{$name}：{$lookup['detail']}";

            match ($lookup['state']) {
                'answer'     => $answers[$name] = $lookup['targets'],
                'missing'    => $missing[]      = $summary,
                'error'      => $errors[]       = $summary,
                default      => $unavailable[]  = $summary,
            };
        }

        if ($answers !== []) {
            $targets = array_values(array_unique(array_merge(...array_values($answers))));
            sort($targets);

            $answerSets = array_values(array_unique(array_map(static function (array $items): string {
                sort($items);

                return implode(',', $items);
            }, $answers)));
            $partial = count($answers) < 2 || count($answerSets) > 1 || $missing !== [] || $errors !== [] || $unavailable !== [];
            $details = [];
            foreach ($answers as $name => $items) {
                $details[] = "{$name}：" . implode(', ', $items);
            }
            $details = [...$details, ...$missing, ...$errors, ...$unavailable];

            return $this->result(
                $partial ? 'partial' : 'ok',
                $targets,
                ($partial ? '公开 DNS 的 PTR 结果尚未完全一致：' : '公开 DNS 已返回 PTR：') . implode('；', $details),
            );
        }

        if ($errors !== []) {
            return $this->result(
                'error',
                [],
                '反向 DNS 权威查询失败：' . implode('；', [...$errors, ...$missing, ...$unavailable]),
            );
        }

        if ($missing !== []) {
            return $this->result(
                'missing',
                [],
                '公开 DNS 尚未发布 PTR：' . implode('；', [...$missing, ...$unavailable]),
            );
        }

        $nativeTargets = $this->nativeTargets($reverse);
        if ($nativeTargets !== []) {
            return $this->result(
                'partial',
                $nativeTargets,
                '公开 DNS 检测服务暂不可用；服务器本地解析器返回 PTR：' . implode(', ', $nativeTargets) . '。',
            );
        }

        $unavailableDetail = $unavailable === []
            ? '未配置可用的 HTTPS 公共 DNS 检测端点。'
            : implode('；', $unavailable);

        return $this->result(
            'unavailable',
            [],
            '公开 DNS 检测服务与服务器本地解析器均未返回可验收结果：' . $unavailableDetail,
        );
    }

    /**
     * @return array{state: string, targets: array<int, string>, detail: string}
     */
    private function lookupProvider(string $name, string $endpoint, string $reverse): array
    {
        try {
            $response = $this->http
                ->withHeaders([
                    'Accept'     => 'application/dns-json',
                    'User-Agent' => 'LONGOS-Mail-PTR-Diagnostics/1.0',
                ])
                ->connectTimeout(min(2, $this->timeout))
                ->timeout($this->timeout)
                ->withOptions([
                    'allow_redirects' => false,
                    'curl'            => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                ])
                ->get($endpoint, ['name' => $reverse, 'type' => 'PTR']);
        } catch (Throwable $exception) {
            return $this->providerResult('unavailable', [], $this->safeText($exception->getMessage()) ?: '连接失败');
        }

        if (! $response->successful()) {
            return $this->providerResult('unavailable', [], 'HTTP ' . $response->status());
        }
        if (strlen($response->body()) > 65536) {
            return $this->providerResult('unavailable', [], '响应过大');
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['Status']) || ! is_numeric($payload['Status'])) {
            return $this->providerResult('unavailable', [], '响应格式无效');
        }

        $status  = (int) $payload['Status'];
        $comment = $this->comment($payload['Comment'] ?? '');
        if ($status !== 0) {
            $label  = $this->rcode($status);
            $detail = $comment === '' ? $label : "{$label}（{$comment}）";

            return $this->providerResult($status === 3 ? 'missing' : 'error', [], $detail);
        }

        $targets = [];
        foreach ((array) ($payload['Answer'] ?? []) as $answer) {
            if (! is_array($answer) || (int) ($answer['type'] ?? 0) !== 12) {
                continue;
            }
            $target = $this->normalizeDomain($answer['data'] ?? '');
            if ($target !== '') {
                $targets[] = $target;
            }
        }
        $targets = array_values(array_unique($targets));
        sort($targets);

        return $targets === []
            ? $this->providerResult('missing', [], 'NOERROR/NODATA')
            : $this->providerResult('answer', $targets, implode(', ', $targets));
    }

    /** @return array<string, string> */
    private function safeEndpoints(): array
    {
        $safe = [];
        foreach ($this->endpoints as $name => $endpoint) {
            $name     = $this->safeText(is_string($name) ? $name : 'DNS');
            $endpoint = trim((string) $endpoint);
            $parts    = parse_url($endpoint);
            if ($name === '' || ! is_array($parts)
                             || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                             || trim((string) ($parts['host'] ?? '')) === ''
                             || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
                continue;
            }
            $safe[$name] = $endpoint;
        }

        return $safe;
    }

    /** @return array<int, string> */
    protected function nativeTargets(string $reverse): array
    {
        if (! function_exists('dns_get_record')) {
            return [];
        }
        $records = @dns_get_record($reverse, DNS_PTR);
        if (! is_array($records)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (array $record): string => $this->normalizeDomain($record['target'] ?? ''),
            $records,
        ))));
    }

    private function normalizeDomain(mixed $domain): string
    {
        $domain = strtolower(trim((string) $domain, " .\t\n\r\0\x0B"));

        return str_contains($domain, '.')
            && filter_var($domain, FILTER_VALIDATE_IP) === false
            && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
                ? $domain
                : '';
    }

    private function comment(mixed $comment): string
    {
        $values = is_array($comment) ? $comment : [$comment];

        return implode('；', array_values(array_filter(array_map(
            fn (mixed $value): string => $this->safeText($value),
            $values,
        ))));
    }

    private function safeText(mixed $value): string
    {
        $value = strip_tags((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value))        ?? '';

        return mb_substr($value, 0, 300);
    }

    private function rcode(int $status): string
    {
        return match ($status) {
            1       => 'FORMERR',
            2       => 'SERVFAIL',
            3       => 'NXDOMAIN',
            4       => 'NOTIMP',
            5       => 'REFUSED',
            default => "DNS RCODE {$status}",
        };
    }

    private static function reverseDnsName(string $address): string
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return '';
        }
        if (strlen($packed) === 4) {
            return implode('.', array_reverse(unpack('C*', $packed))) . '.in-addr.arpa';
        }

        return implode('.', array_reverse(str_split(bin2hex($packed)))) . '.ip6.arpa';
    }

    /** @param array<int, string> $targets
     *  @return array{state: string, targets: array<int, string>, message: string}
     */
    private function result(string $state, array $targets, string $message): array
    {
        return compact('state', 'targets', 'message');
    }

    /** @param array<int, string> $targets
     *  @return array{state: string, targets: array<int, string>, detail: string}
     */
    private function providerResult(string $state, array $targets, string $detail): array
    {
        return compact('state', 'targets', 'detail');
    }
}
