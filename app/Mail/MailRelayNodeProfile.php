<?php

namespace App\Mail;

use InvalidArgumentException;

/**
 * Non-secret deployment profile for the optional second-server mail node.
 *
 * These values are rendered as a Coolify environment block by the LONGOS
 * control panel. Credentials deliberately remain deployment secrets and are
 * never accepted by this profile.
 */
final class MailRelayNodeProfile
{
    public const VERSION = 1;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'inet_protocols'             => 'ipv4',
            'relay_http_port'            => 8788,
            'volume_prefix'              => 'longos-remote-mail',
            'tunnel_protocol'            => 'auto',
            'tunnel_log_level'           => 'info',
            'tunnel_metrics_address'     => '127.0.0.1:20246',
            'cloudflared_image'          => 'cloudflare/cloudflared:2026.10.5',
            'relay_memory_limit'         => '768m',
            'relay_memory_reservation'   => '256m',
            'relay_pids_limit'           => 256,
            'tunnel_memory_limit'        => '256m',
            'tunnel_memory_reservation'  => '64m',
            'tunnel_pids_limit'          => 128,
        ];
    }

    /** @param array<string, mixed> $values
     *  @return array<string, mixed>
     */
    public static function normalize(array $values): array
    {
        $values = array_replace(self::defaults(), $values);

        $inetProtocols = strtolower(trim((string) $values['inet_protocols']));
        if (! in_array($inetProtocols, ['ipv4', 'ipv6', 'all'], true)) {
            throw new InvalidArgumentException('邮件节点网络协议必须是 ipv4、ipv6 或 all。');
        }

        $relayPort = filter_var($values['relay_http_port'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1024, 'max_range' => 65535],
        ]);
        if ($relayPort === false) {
            throw new InvalidArgumentException('邮件节点本机 HTTP 端口必须在 1024-65535 之间。');
        }

        $volumePrefix = strtolower(trim((string) $values['volume_prefix']));
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $volumePrefix) !== 1) {
            throw new InvalidArgumentException('持久卷前缀只能包含小写字母、数字和连字符，且最长 63 个字符。');
        }

        $tunnelProtocol = strtolower(trim((string) $values['tunnel_protocol']));
        if (! in_array($tunnelProtocol, ['auto', 'quic', 'http2'], true)) {
            throw new InvalidArgumentException('Cloudflare Tunnel 协议必须是 auto、quic 或 http2。');
        }

        $logLevel = strtolower(trim((string) $values['tunnel_log_level']));
        if (! in_array($logLevel, ['debug', 'info', 'warn', 'error', 'fatal'], true)) {
            throw new InvalidArgumentException('Cloudflare Tunnel 日志级别无效。');
        }

        $metricsAddress = strtolower(trim((string) $values['tunnel_metrics_address']));
        if (preg_match('/^127\.0\.0\.1:(\d{4,5})$/D', $metricsAddress, $matches) !== 1
            || (int) $matches[1] < 1024
            || (int) $matches[1] > 65535
            || (int) $matches[1] === $relayPort) {
            throw new InvalidArgumentException('Tunnel 指标地址必须是与中继端口不同的 127.0.0.1:1024-65535。');
        }

        $cloudflaredImage = strtolower(trim((string) $values['cloudflared_image']));
        if (preg_match('/^cloudflare\/cloudflared:[0-9][a-z0-9._-]{0,63}$/D', $cloudflaredImage) !== 1) {
            throw new InvalidArgumentException('cloudflared 镜像必须固定为明确版本，不能使用 latest 或第三方镜像。');
        }

        $relayMemoryLimit        = self::memory($values['relay_memory_limit'], '中继内存上限');
        $relayMemoryReservation  = self::memory($values['relay_memory_reservation'], '中继内存预留');
        $tunnelMemoryLimit       = self::memory($values['tunnel_memory_limit'], 'Tunnel 内存上限');
        $tunnelMemoryReservation = self::memory($values['tunnel_memory_reservation'], 'Tunnel 内存预留');
        if (self::memoryBytes($relayMemoryReservation) > self::memoryBytes($relayMemoryLimit)) {
            throw new InvalidArgumentException('中继内存预留不能大于内存上限。');
        }
        if (self::memoryBytes($tunnelMemoryReservation) > self::memoryBytes($tunnelMemoryLimit)) {
            throw new InvalidArgumentException('Tunnel 内存预留不能大于内存上限。');
        }

        return [
            'inet_protocols'            => $inetProtocols,
            'relay_http_port'           => (int) $relayPort,
            'volume_prefix'             => $volumePrefix,
            'tunnel_protocol'           => $tunnelProtocol,
            'tunnel_log_level'          => $logLevel,
            'tunnel_metrics_address'    => $metricsAddress,
            'cloudflared_image'         => $cloudflaredImage,
            'relay_memory_limit'        => $relayMemoryLimit,
            'relay_memory_reservation'  => $relayMemoryReservation,
            'relay_pids_limit'          => self::pids($values['relay_pids_limit'], '中继'),
            'tunnel_memory_limit'       => $tunnelMemoryLimit,
            'tunnel_memory_reservation' => $tunnelMemoryReservation,
            'tunnel_pids_limit'         => self::pids($values['tunnel_pids_limit'], 'Tunnel'),
        ];
    }

    /** @param array<string, mixed> $topology
     *  @return array<int, string>
     */
    public static function environmentLines(array $topology): array
    {
        $node = self::normalize(is_array($topology['node'] ?? null) ? $topology['node'] : []);

        return [
            'MAIL_NODE_PROFILE_VERSION=' . self::VERSION,
            'MAIL_HOSTNAME=' . (string) ($topology['hostname'] ?? ''),
            'MAIL_PUBLIC_IPS=' . implode(',', (array) ($topology['public_ips'] ?? [])),
            'MAIL_DOMAINS=' . implode(',', (array) ($topology['domains'] ?? [])),
            'ALLOWED_SENDER_DOMAINS=' . implode(',', (array) ($topology['allowed_sender_domains'] ?? [])),
            'DKIM_SELECTOR=' . (string) ($topology['dkim_selector'] ?? ''),
            'INET_PROTOCOLS=' . $node['inet_protocols'],
            'RELAY_HTTP_PORT=' . $node['relay_http_port'],
            'MAIL_NODE_VOLUME_PREFIX=' . $node['volume_prefix'],
            'TUNNEL_PROTOCOL=' . $node['tunnel_protocol'],
            'TUNNEL_LOG_LEVEL=' . $node['tunnel_log_level'],
            'TUNNEL_METRICS_ADDRESS=' . $node['tunnel_metrics_address'],
            'CLOUDFLARED_IMAGE=' . $node['cloudflared_image'],
            'RELAY_MEMORY_LIMIT=' . $node['relay_memory_limit'],
            'RELAY_MEMORY_RESERVATION=' . $node['relay_memory_reservation'],
            'RELAY_PIDS_LIMIT=' . $node['relay_pids_limit'],
            'TUNNEL_MEMORY_LIMIT=' . $node['tunnel_memory_limit'],
            'TUNNEL_MEMORY_RESERVATION=' . $node['tunnel_memory_reservation'],
            'TUNNEL_PIDS_LIMIT=' . $node['tunnel_pids_limit'],
        ];
    }

    private static function memory(mixed $value, string $label): string
    {
        $memory = strtolower(trim((string) $value));
        if (preg_match('/^[1-9][0-9]{0,5}[kmg]$/D', $memory) !== 1) {
            throw new InvalidArgumentException("{$label}必须是如 256m 或 1g 的 Docker 内存值。");
        }

        return $memory;
    }

    private static function memoryBytes(string $memory): int
    {
        $unit = substr($memory, -1);
        $size = (int) substr($memory, 0, -1);

        return $size * match ($unit) {
            'g'     => 1024 * 1024 * 1024,
            'm'     => 1024 * 1024,
            default => 1024,
        };
    }

    private static function pids(mixed $value, string $label): int
    {
        $pids = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 32, 'max_range' => 4096],
        ]);
        if ($pids === false) {
            throw new InvalidArgumentException("{$label} PID 上限必须在 32-4096 之间。");
        }

        return (int) $pids;
    }
}
