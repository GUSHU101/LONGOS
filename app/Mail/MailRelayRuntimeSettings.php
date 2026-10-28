<?php

namespace App\Mail;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Platform-wide, non-secret mail relay topology managed from the control site.
 *
 * Tenant databases are deliberately not used here: app, worker and scheduler
 * share the Laravel storage volume, so every tenant and process reads the same
 * outbound route. HMAC credentials remain deployment secrets and are never
 * accepted by or written to this file.
 */
final class MailRelayRuntimeSettings
{
    private const SCHEMA = 1;

    private const MAX_FILE_BYTES = 65536;

    /** @var array{mode: string, endpoint: string, hostname: string, public_ips: array<int, string>, auto_detect_public_ips: bool, domains: array<int, string>, allowed_sender_domains: array<int, string>, platform_sender_domain: string, dkim_selector: string} */
    private array $environment;

    private ?string $lastError = null;

    /** @param array<string, mixed>|null $environment */
    public function __construct(
        private readonly ?string $pathOverride = null,
        ?array $environment = null,
    ) {
        $this->environment = $this->normalize($environment ?? $this->fromConfig(), false);
    }

    /**
     * @return array{mode: string, endpoint: string, hostname: string, public_ips: array<int, string>, auto_detect_public_ips: bool, domains: array<int, string>, allowed_sender_domains: array<int, string>, platform_sender_domain: string, dkim_selector: string}
     */
    public function current(): array
    {
        $this->lastError = null;
        if (! is_file($this->path())) {
            return $this->environment;
        }

        try {
            return $this->readPersisted();
        } catch (Throwable $exception) {
            $this->lastError = $exception->getMessage();
            Log::critical('Mail relay runtime settings are invalid; deployment defaults remain active.', [
                'exception' => $exception::class,
            ]);

            return $this->environment;
        }
    }

    /**
     * @return array{values: array<string, mixed>, deployment_values: array<string, mixed>, source: string, updated_at: string|null, error: string|null, secret_status: array{longos: bool, cloud_mail: bool, distinct: bool}}
     */
    public function status(): array
    {
        $values    = $this->current();
        $persisted = is_file($this->path()) && $this->lastError === null;
        $updatedAt = $persisted ? filemtime($this->path()) : false;

        return [
            'values'            => $values,
            'deployment_values' => $this->environment,
            'source'            => $this->lastError !== null ? 'invalid' : ($persisted ? 'control_panel' : 'deployment'),
            'updated_at'        => is_int($updatedAt) ? date(DATE_ATOM, $updatedAt) : null,
            'error'             => $this->lastError,
            'secret_status'     => [
                'longos'     => (bool) config('mail.relay.longos_client_configured'),
                'cloud_mail' => (bool) config('mail.relay.cloud_mail_client_configured'),
                'distinct'   => (bool) config('mail.relay.client_secrets_distinct'),
            ],
        ];
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function save(array $input): array
    {
        if (strtolower(trim((string) ($input['mode'] ?? ''))) === 'internal') {
            // The built-in Postfix/OpenDKIM container receives its identity
            // from Compose and cannot be reconfigured by the web process.
            // Preserve that shared identity while still allowing an operator
            // to override or auto-detect only the public egress address.
            $input = array_replace($input, [
                'endpoint'               => 'http://mail-relay:3000',
                'hostname'               => $this->environment['hostname'],
                'domains'                => $this->environment['domains'],
                'allowed_sender_domains' => $this->environment['allowed_sender_domains'],
                'platform_sender_domain' => $this->environment['platform_sender_domain'],
                'dkim_selector'          => $this->environment['dkim_selector'],
            ]);
        }
        $values  = $this->normalize($input, true);
        $payload = json_encode([
            'schema'     => self::SCHEMA,
            'updated_at' => date(DATE_ATOM),
            'values'     => $values,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->withLock(LOCK_EX, function () use ($payload): void {
            $path = $this->path();
            if (is_link($path)) {
                throw new RuntimeException('邮件出口配置文件不能是符号链接。');
            }
            if (file_put_contents($path, $payload . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('无法写入共享邮件出口配置，请检查 storage 卷权限。');
            }
            @chmod($path, 0600);
        });

        $this->lastError = null;
        $this->applyToConfig($values);

        return $values;
    }

    public function reset(): void
    {
        $this->withLock(LOCK_EX, function (): void {
            $path = $this->path();
            if (is_link($path)) {
                throw new RuntimeException('邮件出口配置文件不能是符号链接。');
            }
            if (is_file($path) && ! @unlink($path)) {
                throw new RuntimeException('无法恢复部署默认值，请检查 storage 卷权限。');
            }
        });
        $this->lastError = null;
        $this->applyToConfig($this->environment);
    }

    /** @param array<string, mixed>|null $values */
    public function applyToConfig(?array $values = null): void
    {
        $values = $values === null ? $this->current() : $this->normalize($values, false);

        config([
            'mail.mailers.self_hosted.endpoint' => $values['endpoint'],
            'mail.relay.hostname'               => $values['hostname'],
            'mail.relay.public_ips'             => $values['public_ips'],
            'mail.relay.auto_detect_public_ips' => $values['auto_detect_public_ips'],
            'mail.relay.domains'                => implode(',', $values['domains']),
            'mail.relay.allowed_sender_domains' => $values['allowed_sender_domains'],
            'mail.relay.platform_sender_domain' => $values['platform_sender_domain'],
            'mail.relay.dkim_selector'          => $values['dkim_selector'],
            'mail.relay.runtime_source'         => is_file($this->path()) && $this->lastError === null
                ? 'control_panel'
                : 'deployment',
        ]);
    }

    /** @return array<string, mixed> */
    private function readPersisted(): array
    {
        return $this->withLock(LOCK_SH, function (): array {
            $path = $this->path();
            if (is_link($path) || ! is_readable($path)) {
                throw new RuntimeException('共享邮件出口配置不可安全读取。');
            }
            $size = filesize($path);
            if (! is_int($size) || $size < 2 || $size > self::MAX_FILE_BYTES) {
                throw new RuntimeException('共享邮件出口配置大小无效。');
            }
            $payload = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($payload)
                || (int) ($payload['schema'] ?? 0) !== self::SCHEMA
                || ! is_array($payload['values'] ?? null)) {
                throw new RuntimeException('共享邮件出口配置版本无效。');
            }

            return $this->alignInternalIdentity($this->normalize($payload['values'], true));
        });
    }

    /** @param array<string, mixed> $values
     *  @return array<string, mixed>
     */
    private function alignInternalIdentity(array $values): array
    {
        if (($values['mode'] ?? null) !== 'internal') {
            return $values;
        }

        return array_replace($values, [
            'endpoint'               => 'http://mail-relay:3000',
            'hostname'               => $this->environment['hostname'],
            'domains'                => $this->environment['domains'],
            'allowed_sender_domains' => $this->environment['allowed_sender_domains'],
            'platform_sender_domain' => $this->environment['platform_sender_domain'],
            'dkim_selector'          => $this->environment['dkim_selector'],
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @return array{mode: string, endpoint: string, hostname: string, public_ips: array<int, string>, auto_detect_public_ips: bool, domains: array<int, string>, allowed_sender_domains: array<int, string>, platform_sender_domain: string, dkim_selector: string}
     */
    private function normalize(array $values, bool $strict): array
    {
        $mode = strtolower(trim((string) ($values['mode'] ?? 'internal')));
        if (! in_array($mode, ['internal', 'external'], true)) {
            throw new InvalidArgumentException('请选择同机内置或第二台服务器出口模式。');
        }

        $endpoint = $mode === 'internal'
            ? 'http://mail-relay:3000'
            : rtrim(trim((string) ($values['endpoint'] ?? '')), '/');
        if ($mode === 'external' && $strict && ! $this->validExternalEndpoint($endpoint)) {
            throw new InvalidArgumentException('第二台服务器中继地址必须是公开域名的 HTTPS 地址，不得包含凭据、查询参数或非 443 端口。');
        }

        $hostname = $this->domain($values['hostname'] ?? null);
        if ($hostname === '' && $strict) {
            throw new InvalidArgumentException('邮件主机名必须是有效的公共域名，例如 mailout.example.com。');
        }

        $publicIps = $this->publicIps($this->list($values['public_ips'] ?? []));
        if ($mode === 'external' && $strict && $publicIps === []) {
            throw new InvalidArgumentException('第二台邮件服务器必须填写至少一个可设置 PTR/rDNS 的真实公网 IP。');
        }
        if (count($publicIps) > 8) {
            throw new InvalidArgumentException('邮件出口公网 IP 最多允许配置 8 个。');
        }

        $domains = $this->domains($this->list($values['domains'] ?? []));
        if ($domains === [] && $strict) {
            throw new InvalidArgumentException('至少填写一个 DKIM 签名域。');
        }
        if (count($domains) > 50) {
            throw new InvalidArgumentException('DKIM 签名域最多允许配置 50 个。');
        }

        $allowed = $this->domains($this->list($values['allowed_sender_domains'] ?? []));
        if ($strict && ($allowed === [] || array_diff($allowed, $domains) !== [])) {
            throw new InvalidArgumentException('允许发件域必须非空，并且全部包含在 DKIM 签名域中。');
        }

        $platform = $this->domain($values['platform_sender_domain'] ?? null);
        if ($strict && ($platform === '' || ! in_array($platform, $allowed, true))) {
            throw new InvalidArgumentException('平台默认发件域必须包含在允许发件域中。');
        }

        $selector = strtolower(trim((string) ($values['dkim_selector'] ?? 'mail2026')));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D', $selector) !== 1) {
            throw new InvalidArgumentException('DKIM Selector 只能包含小写字母、数字、下划线或连字符，且最长 63 个字符。');
        }

        return [
            'mode'                       => $mode,
            'endpoint'                   => $endpoint,
            'hostname'                   => $hostname,
            'public_ips'                 => $publicIps,
            'auto_detect_public_ips'     => $mode === 'internal'
                && filter_var($values['auto_detect_public_ips'] ?? true, FILTER_VALIDATE_BOOL),
            'domains'                    => $domains,
            'allowed_sender_domains'     => $allowed,
            'platform_sender_domain'     => $platform,
            'dkim_selector'              => $selector,
        ];
    }

    /** @return array<string, mixed> */
    private function fromConfig(): array
    {
        $endpoint = rtrim(trim((string) config('mail.mailers.self_hosted.endpoint', 'http://mail-relay:3000')), '/');
        $host     = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        return [
            'mode'                       => in_array($host, ['mail-relay', '127.0.0.1', 'localhost'], true) ? 'internal' : 'external',
            'endpoint'                   => $endpoint,
            'hostname'                   => config('mail.relay.hostname'),
            'public_ips'                 => config('mail.relay.public_ips', []),
            'auto_detect_public_ips'     => config('mail.relay.auto_detect_public_ips', true),
            'domains'                    => config('mail.relay.domains', ''),
            'allowed_sender_domains'     => config('mail.relay.allowed_sender_domains', []),
            'platform_sender_domain'     => config('mail.relay.platform_sender_domain'),
            'dkim_selector'              => config('mail.relay.dkim_selector', 'mail2026'),
        ];
    }

    private function validExternalEndpoint(string $endpoint): bool
    {
        if ($endpoint === '' || strlen($endpoint) > 2048 || ! filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($endpoint);
        $host  = $this->domain($parts['host'] ?? null);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && $host !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && (! isset($parts['port']) || (int) $parts['port'] === 443);
    }

    /** @return array<int, string> */
    private function list(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn (mixed $item): string => trim((string) $item),
                $value,
            ), static fn (string $item): bool => $item !== ''));
        }

        return array_values(array_filter(
            preg_split('/[\s,]+/u', trim((string) $value)) ?: [],
            static fn (string $item): bool => $item !== '',
        ));
    }

    /** @param array<int, string> $values
     *  @return array<int, string>
     */
    private function domains(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $value): string => $this->domain($value),
            $values,
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

    /** @param array<int, string> $values
     *  @return array<int, string>
     */
    private function publicIps(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static function (string $value): string {
                $packed = @inet_pton($value);

                return $packed === false ? '' : (inet_ntop($packed) ?: '');
            },
            $values,
        ), static fn (string $value): bool => filter_var(
            $value,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE,
        ) !== false)));
    }

    private function path(): string
    {
        return $this->pathOverride ?: (string) config(
            'mail.relay.runtime_settings_path',
            storage_path('app/longos/mail-relay-runtime.json'),
        );
    }

    /** @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLock(int $operation, callable $callback): mixed
    {
        $directory = dirname($this->path());
        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('无法创建共享邮件出口配置目录。');
        }
        $lockPath = $this->path() . '.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('邮件出口配置锁文件不能是符号链接。');
        }
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || ! flock($lock, $operation)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new RuntimeException('无法锁定共享邮件出口配置。');
        }

        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
