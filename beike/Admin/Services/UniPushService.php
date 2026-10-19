<?php

namespace Beike\Admin\Services;

use App\Saas\Support\SensitiveValueRedactor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class UniPushService
{
    private $httpClient;

    public function __construct()
    {
        $this->httpClient = Http::withOptions([
            'verify' => config('beike.http_verify_ssl', true),
        ])->withHeaders([
            'Content-Type' => 'application/json',
        ])->connectTimeout(3)->timeout(10);
    }

    /**
     * @return UniPushService
     */
    public static function getInstance(): self
    {
        return new self;
    }

    /**
     * 推送已发货消息
     *
     * @param             $message
     * @throws \Exception
     */
    public function pushOrderStatus($message)
    {
        $cloudFunctionUrl = system_setting('base.unipush_api_url');
        if (empty($cloudFunctionUrl)) {
            return json_fail(__('admin/app_push.api_url_err'));
        }

        $requestId = Str::random(random_int(16, 32));
        $linkType  = (string) ($message['link']['type'] ?? '');
        $linkValue = (string) ($message['link']['value'] ?? '');

        $requestData = [
            'push_clientid'      => $message['push_clientid'] ?? '',
            'request_id'         => $requestId,
            'force_notification' => true,
            'title'              => $message['title'] ?? '默认标题',
            'badge'              => '+1',
            'content'            => $message['content'] ?? '默认内容',
            'payload'            => [
                'product_id'  => $linkType === 'product' ? $linkValue : '',
                'category_id' => $linkType === 'category' ? $linkValue : '',
                'page_id'     => $linkType === 'page' ? $linkValue : '',
                'order_id'    => $linkType === 'order' ? $linkValue : '',
                'custom'      => $linkType === 'custom' ? $linkValue : '',
                'customer_id' => $message['customer_id'] ?? '',
            ],
        ];

        try {
            [$cloudFunctionUrl, $resolve] = $this->validatedEndpoint((string) $cloudFunctionUrl);
            $response                     = $this->httpClient
                ->withOptions(['curl' => [CURLOPT_RESOLVE => [$resolve]]])
                ->post($cloudFunctionUrl, $requestData);
            $result   = $response->json();

            if ($response->successful() && ($result['errCode'] ?? 0) == 0) {
                return json_success('推送成功');
            }
            $errMsg = $result['errMsg'] ?? '未知错误';

            return json_fail($errMsg);

        } catch (\Throwable $e) {
            Log::warning('App push request failed.', [
                'exception' => $e::class,
                'message'   => SensitiveValueRedactor::redact($e->getMessage()),
            ]);

            return json_fail('推送请求失败，请检查推送服务配置。');
        }
    }

    /** @return array{string, string} */
    private function validatedEndpoint(string $url): array
    {
        $url   = trim($url);
        $parts = filter_var($url, FILTER_VALIDATE_URL) !== false ? parse_url($url) : false;
        $host  = is_array($parts) ? trim(strtolower((string) ($parts['host'] ?? '')), '[]') : '';
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! preg_match('/^[a-z0-9.:-]+$/i', $host)) {
            throw new RuntimeException('推送服务地址必须是有效的 HTTPS 公网地址。');
        }

        $port = (int) ($parts['port'] ?? 443);
        if ($port !== 443) {
            throw new RuntimeException('推送服务仅允许使用 HTTPS 443 端口。');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : array_values(array_filter(array_map(
                static fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
                dns_get_record($host, DNS_A | DNS_AAAA) ?: [],
            )));
        if ($addresses === [] || array_filter($addresses, fn (string $address): bool => ! $this->isPublicAddress($address)) !== []) {
            throw new RuntimeException('推送服务域名必须仅解析到公网地址。');
        }

        $address = $addresses[0];
        $address = str_contains($address, ':') ? "[{$address}]" : $address;

        return [$url, "{$host}:443:{$address}"];
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
