<?php

namespace App\Saas;

final class CaddyEdgeStatus
{
    public static function platform(mixed $value): string
    {
        $platform = strtolower(trim((string) $value));

        return $platform === 'coolify' ? $platform : 'invalid';
    }

    public static function healthy(bool $automaticTls, bool $caddyObserved, string $platform): bool
    {
        return $automaticTls && $caddyObserved && self::platform($platform) !== 'invalid';
    }

    public static function warning(bool $automaticTls, bool $caddyObserved, string $platform): ?string
    {
        if (! $automaticTls) {
            return '当前没有启用 Caddy On-Demand TLS，租户自定义域名不会自动签发证书。';
        }

        if (self::platform($platform) === 'invalid') {
            return '部署平台配置无效。生产环境应使用 Coolify 的集成 Caddy 入口。';
        }

        if (! $caddyObserved) {
            return '当前请求未经过已认证的 Coolify Caddy 入口。请确认服务器代理已切换为 Caddy、应用使用 Raw Compose，并已连接 coolify 网络。';
        }

        return null;
    }
}

