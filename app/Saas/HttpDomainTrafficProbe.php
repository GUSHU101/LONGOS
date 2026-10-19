<?php

namespace App\Saas;

use App\Saas\Contracts\DnsRouteResolver;
use App\Saas\Contracts\DomainTrafficProbe;
use App\Saas\Models\Domain;
use App\Saas\Support\SensitiveValueRedactor;
use Beike\Services\CloudflareIpRangeService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class HttpDomainTrafficProbe implements DomainTrafficProbe
{
    public function __construct(private readonly ?DnsRouteResolver $routingDns = null) {}

    public function probe(Domain $domain): array
    {
        try {
            $route = $this->verifiedRoute($domain);
            if ($route['public'] === []) {
                throw new RuntimeException('HTTPS 探测前的 DNS 结果不再指向已验证的 LONGOS 或 Cloudflare 入口。');
            }

            $caddy   = strtolower((string) config('saas.tls.driver', 'caddy')) === 'caddy';
            $timeout = $caddy
                ? max(10, (int) config('saas.custom_domains.caddy_probe_timeout_seconds', 45))
                : max(2, (int) config('saas.custom_domains.probe_timeout_seconds', 8));

            // A proxied Cloudflare hostname resolves only to Cloudflare
            // anycast addresses. Warm the verified LONGOS origin first so a
            // brand-new Caddy On-Demand certificate can be issued before
            // Cloudflare performs its Full (strict) origin handshake. The
            // public Cloudflare request below remains the decisive check.
            $originError = null;
            if ($caddy && $route['cloudflare'] && $route['origin'] !== []) {
                try {
                    $originResponse = $this->request($domain, $route['origin'], $timeout);
                    $originReady    = $originResponse->status() === 204
                        && hash_equals('ready', (string) $originResponse->header('X-Longos-Domain-Probe'));
                    $strictOriginGuard = strtolower((string) config('longos.cloudflare.mode', 'off')) === 'required'
                        && $originResponse->status()                                                  === 403;
                    if (! $originReady && ! $strictOriginGuard) {
                        $originError = $this->responseError($originResponse);
                    }
                } catch (Throwable $exception) {
                    $originError = SensitiveValueRedactor::redact($exception->getMessage());
                }
            }

            $response = $this->request($domain, $route['public'], $timeout);

            if ($response->status() === 204 && hash_equals('ready', (string) $response->header('X-Longos-Domain-Probe'))) {
                return ['ok' => true, 'error' => null];
            }

            $error = $this->responseError($response);
            if ($originError !== null) {
                $error .= ' Caddy 源站预热同时失败：' . $originError;
            }

            return [
                'ok'    => false,
                'error' => $error,
            ];
        } catch (Throwable $exception) {
            return [
                'ok'    => false,
                'error' => 'HTTPS 回源探测失败：' . SensitiveValueRedactor::redact($exception->getMessage()),
            ];
        }
    }

    /** @param string[] $addresses */
    private function request(Domain $domain, array $addresses, int $timeout): Response
    {
        $resolve = $domain->host . ':443:' . implode(',', array_map(
            static fn (string $address): string => str_contains($address, ':') ? "[{$address}]" : $address,
            array_slice($addresses, 0, 8),
        ));

        return Http::accept('text/plain')
            ->connectTimeout(max(1, (int) config('saas.custom_domains.probe_connect_timeout_seconds', 3)))
            ->timeout($timeout)
            ->withoutRedirecting()
            ->withOptions(['curl' => [CURLOPT_RESOLVE => [$resolve]]])
            ->get('https://' . $domain->host . (string) config('saas.custom_domains.probe_path'), [
                'id'        => $domain->getKey(),
                'signature' => DomainProbeSignature::make($domain),
            ]);
    }

    private function responseError(Response $response): string
    {
        $status   = $response->status();
        $server   = strtolower(trim((string) $response->header('Server')));
        $location = trim((string) $response->header('Location'));

        if ($status === 526) {
            return 'Cloudflare 返回 HTTP 526：源站没有提供该租户域名的有效证书。请确认 LONGOS Caddy 已接管公网 443，且不要把 Full (strict) 降级为不安全模式。';
        }
        if ($status === 525) {
            return 'Cloudflare 返回 HTTP 525：Cloudflare 无法与源站完成 TLS 握手。请检查 Caddy 是否运行并实际监听公网 443。';
        }
        if (in_array($status, [301, 302, 307, 308], true)) {
            $target = $this->safeRedirectTarget($location);

            return "HTTPS 入口返回 HTTP {$status}{$target}，签名探测没有进入当前 LONGOS 实例。请检查 Coolify Caddy 路由及 Cloudflare Redirect Rules 是否抢先处理了该 Host。";
        }
        if ($status === 403 && str_contains($server, 'cloudflare')) {
            return 'Cloudflare 返回 HTTP 403，签名探测被 Cloudflare WAF/访问规则拦截，未到达当前 LONGOS 实例。';
        }

        return "HTTPS 回源探测未到达当前 LONGOS 站点（HTTP {$status}）。";
    }

    private function safeRedirectTarget(string $location): string
    {
        if ($location === '') {
            return '';
        }

        $parts = parse_url($location);
        if (! is_array($parts)) {
            return '';
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || ! preg_match('/^[a-z0-9.-]+$/', $host)) {
            return '';
        }

        return "（重定向到 {$host}）";
    }

    /** @return array{public: string[], origin: string[], cloudflare: bool} */
    private function verifiedRoute(Domain $domain): array
    {
        $resolver  = $this->routingDns ?? app(DnsRouteResolver::class);
        $addresses = $this->addresses($resolver->resolve($domain->host));
        if ($addresses === []) {
            return ['public' => [], 'origin' => [], 'cloudflare' => false];
        }

        $expected = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtolower(rtrim(trim((string) $value), '.')),
            (array) config('saas.dns_targets', []),
        ))));
        $expectedRecords = $expected;
        foreach ($expected as $target) {
            $expectedRecords = [...$expectedRecords, ...$resolver->resolve($target)];
        }
        $expectedAddresses = $this->addresses($expectedRecords);
        $allExpected       = $expectedAddresses !== [] && array_diff($addresses, $expectedAddresses) === [];

        $allCloudflare = false;
        if (strtolower((string) config('longos.cloudflare.mode', 'off')) !== 'off') {
            $cloudflare    = new CloudflareIpRangeService;
            $allCloudflare = array_reduce(
                $addresses,
                static fn (bool $valid, string $address): bool => $valid && $cloudflare->contains($address),
                true,
            );
        }

        return [
            'public'     => $allExpected || $allCloudflare ? $addresses : [],
            'origin'     => $expectedAddresses,
            'cloudflare' => $allCloudflare,
        ];
    }

    /** @param array<int, string> $records @return string[] */
    private function addresses(array $records): array
    {
        return array_values(array_unique(array_filter(
            $records,
            static fn (string $value): bool => filter_var($value, FILTER_VALIDATE_IP) !== false,
        )));
    }
}
