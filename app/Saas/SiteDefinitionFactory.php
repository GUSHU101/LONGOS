<?php

namespace App\Saas;

use App\Saas\Support\DomainName;
use Beike\Services\TimezoneService;
use Illuminate\Container\Container;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SiteDefinitionFactory
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function fromFile(string $path, array $defaults = []): array
    {
        if (! @is_file($path) || ! @is_readable($path)) {
            throw new InvalidArgumentException("找不到可读的批量建站文件：{$path}");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'json') {
            $items = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($items) || ! array_is_list($items)) {
                throw new InvalidArgumentException('JSON 文件顶层必须是站点数组。');
            }
        } elseif ($extension === 'csv') {
            $items = $this->readCsv($path);
        } else {
            throw new InvalidArgumentException('批量建站文件仅支持 .csv 或 .json。');
        }

        $this->assertBatchSize(count($items));
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('批量建站文件第 ' . ($index + 1) . ' 项必须是对象。');
            }
        }

        return array_map(fn (array $item): array => $this->normalize($item, $defaults), $items);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fromCount(int $count, array $defaults = []): array
    {
        if ($count < 1) {
            return [];
        }
        $this->assertBatchSize($count);

        $prefix = Str::slug((string) ($defaults['prefix'] ?? 'site')) ?: 'site';
        $width  = max(3, strlen((string) $count));
        $items  = [];

        for ($index = 1; $index <= $count; $index++) {
            $code    = $prefix . str_pad((string) $index, $width, '0', STR_PAD_LEFT);
            $items[] = $this->normalize([
                'code' => $code,
                'name' => (string) ($defaults['name_prefix'] ?? '站点') . ' ' . $index,
            ], $defaults);
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(array $item, array $defaults = []): array
    {
        $item = array_merge($defaults, array_filter($item, static fn ($value): bool => $value !== null && $value !== ''));
        $code = strtolower(Str::slug((string) ($item['code'] ?? '')));
        if ($code === '' || strlen($code) > 63 || ! preg_match('/^[a-z0-9][a-z0-9-]*$/', $code)) {
            throw new InvalidArgumentException('站点 code 必须是 1-63 位小写字母、数字或连字符。');
        }
        $reserved = array_map('strtolower', (array) $this->configuration(
            'saas.domains.reserved_subdomains',
            ['www', 'admin', 'api', 'app', 'mail', 'ftp', 'smtp', 'pop', 'imap', 'support', 'status', 'docs', 'cdn', 'static', 'assets', 'ns1', 'ns2'],
        ));
        if (in_array($code, $reserved, true)) {
            throw new InvalidArgumentException("站点 code {$code} 是平台保留子域名。");
        }

        $configuredBaseValue = trim((string) $this->configuration('saas.base_domain', ''));
        $requestedBaseValue  = trim((string) ($item['base_domain'] ?? ''));
        if ($configuredBaseValue !== '' && $requestedBaseValue !== '') {
            $configuredBase = DomainName::normalize($configuredBaseValue);
            $requestedBase  = DomainName::normalize($requestedBaseValue);
            if ($configuredBase !== $requestedBase) {
                throw new InvalidArgumentException('批量建站的 base_domain 必须与平台 SAAS_BASE_DOMAIN 一致。');
            }
        }
        $platformBaseValue = $configuredBaseValue ?: $requestedBaseValue;
        if ($platformBaseValue === '') {
            throw new InvalidArgumentException("站点 {$code} 无法确定平台 SAAS_BASE_DOMAIN。");
        }
        $platformBase = DomainName::normalize($platformBaseValue);

        $domainValues = $item['domains'] ?? $item['domain'] ?? null;
        if (is_string($domainValues)) {
            $domainValues = preg_split('/[|;]+/', $domainValues, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (! is_array($domainValues) || $domainValues === []) {
            $domainValues = ["{$code}.{$platformBase}"];
        }

        $domains = [];
        foreach (array_values($domainValues) as $index => $value) {
            $domain = DomainName::parse((string) $value, (string) ($item['scheme'] ?? $this->configuration('saas.default_scheme', 'https')));
            $this->assertPlatformHost($domain['host'], $platformBase);
            $domain['is_primary']     = $index === 0;
            $domains[$domain['host']] = $domain;
        }
        $domains                  = array_values($domains);
        $domains[0]['is_primary'] = true;

        $database = (string) ($item['database'] ?? $item['database_name'] ?? '');
        if ($database === '') {
            $prefix   = (string) ($item['database_prefix'] ?? $this->configuration('saas.database_prefix', 'longos_'));
            $suffix   = str_replace('-', '_', $code);
            $database = $prefix . $suffix;
            if (strlen($database) > 64) {
                $hash      = '_' . substr(hash('sha256', $database), 0, 10);
                $database  = $prefix . substr($suffix, 0, max(1, 64 - strlen($prefix) - strlen($hash))) . $hash;
            }
        }
        if (strlen($database) > 64 || ! preg_match('/^[a-zA-Z0-9_]+$/', $database)) {
            throw new InvalidArgumentException("站点 {$code} 的数据库名仅能包含字母、数字和下划线，且不能超过 64 位。");
        }

        $primaryHost = $domains[0]['host'];
        $adminEmail  = trim((string) ($item['admin_email'] ?? ''));
        if ($adminEmail === '') {
            $adminEmail = filter_var($primaryHost, FILTER_VALIDATE_DOMAIN)
                ? "admin@{$primaryHost}"
                : "admin@{$code}.local";
        }
        if (! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("站点 {$code} 的 admin_email 无效。");
        }

        $timezone = (string) ($item['timezone'] ?? 'Asia/Shanghai');
        if (! TimezoneService::isSupported($timezone)) {
            throw new InvalidArgumentException("站点 {$code} 的 timezone 无效。");
        }
        $timezone = TimezoneService::normalize($timezone);
        $locale   = (string) ($item['locale'] ?? 'zh_cn');
        if (! preg_match('/^[a-z]{2,3}(?:[_-][a-z0-9]{2,8})?$/i', $locale)) {
            throw new InvalidArgumentException("站点 {$code} 的 locale 无效。");
        }

        $name = trim((string) ($item['name'] ?? $code)) ?: $code;
        if (mb_strlen($name) > 255) {
            throw new InvalidArgumentException("站点 {$code} 的名称不能超过 255 个字符。");
        }

        $adminPassword = (string) ($item['admin_password'] ?? Str::password(20));
        if (strlen($adminPassword) < 12 || strlen($adminPassword) > 128) {
            throw new InvalidArgumentException("站点 {$code} 的 admin_password 必须为 12-128 位。");
        }

        $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
        // These keys belong to the control plane and must not be supplied by
        // CSV/JSON/API callers.
        unset(
            $meta['control_site'],
            $meta['database_created'],
            $meta['database_creation_pending'],
            $meta['database_mode'],
            $meta['database_prefix'],
            $meta['last_error'],
        );
        if (array_key_exists('isolate_files', $meta)) {
            $meta['isolate_files'] = filter_var($meta['isolate_files'], FILTER_VALIDATE_BOOL);
        }

        $databaseMode = strtolower((string) $this->configuration('saas.database_mode', 'prefix'));
        if (! in_array($databaseMode, ['prefix', 'database'], true)) {
            throw new InvalidArgumentException('SAAS_DATABASE_MODE 仅支持 prefix 或 database。');
        }
        $meta['database_mode'] = $databaseMode;
        if ($databaseMode === 'prefix') {
            // A compact deterministic prefix stays well below MySQL's
            // 64-character identifier limit even for the longest LONGOS table.
            $meta['database_prefix'] = 'lt_' . substr(hash('sha256', $database), 0, 12) . '_';
        }

        return [
            'code'           => $code,
            'name'           => $name,
            'database_name'  => $database,
            'domains'        => $domains,
            'locale'         => $locale,
            'timezone'       => $timezone,
            'admin_email'    => $adminEmail,
            'admin_password' => $adminPassword,
            'meta'           => $meta,
        ];
    }

    /** @return array<int, array<string, string>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException("无法打开 CSV 文件：{$path}");
        }

        try {
            $headers = fgetcsv($handle, null, ',', '"', '');
            if (! is_array($headers)) {
                throw new InvalidArgumentException('CSV 文件缺少表头。');
            }
            $headers = array_map(static fn (string $header): string => trim($header, "\xEF\xBB\xBF \t\n\r\0\x0B"), $headers);
            if (in_array('', $headers, true) || count(array_unique($headers)) !== count($headers)) {
                throw new InvalidArgumentException('CSV 表头不能为空或重复。');
            }
            $rows    = [];
            $line    = 1;
            while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $line++;
                if ($values === [null] || $values === []) {
                    continue;
                }
                if (count($values) > count($headers)) {
                    throw new InvalidArgumentException("CSV 第 {$line} 行字段数量超过表头。");
                }
                $values = array_pad($values, count($headers), '');
                $rows[] = array_combine($headers, array_slice($values, 0, count($headers)));
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function configuration(string $key, mixed $default = null): mixed
    {
        $container = Container::getInstance();

        return $container && $container->bound('config')
            ? $container->make('config')->get($key, $default)
            : $default;
    }

    private function assertBatchSize(int $count): void
    {
        $maximum = max(1, (int) $this->configuration('saas.max_batch_size', 100));
        if ($count > $maximum) {
            throw new InvalidArgumentException("单次最多定义 {$maximum} 个站点。");
        }
    }

    private function assertPlatformHost(string $host, string $baseDomain): void
    {
        if ($host === $baseDomain || ! str_ends_with($host, '.' . $baseDomain)) {
            throw new InvalidArgumentException(
                "批量建站只能直接绑定 {$baseDomain} 下的平台子域名；客户自有域名请在建站后通过 TXT 验证流程添加。"
            );
        }

        foreach ((array) $this->configuration('saas.control_domains', []) as $controlDomain) {
            try {
                $controlHost = DomainName::normalize((string) $controlDomain);
            } catch (\Throwable) {
                continue;
            }
            if ($host === $controlHost) {
                throw new InvalidArgumentException("控制站域名 {$controlHost} 不能分配给租户。");
            }
        }

        $relative = substr($host, 0, -strlen('.' . $baseDomain));
        $reserved = array_map('strtolower', (array) $this->configuration('saas.domains.reserved_subdomains', []));
        if (! str_contains($relative, '.') && in_array($relative, $reserved, true)) {
            throw new InvalidArgumentException("平台子域名 {$relative} 已被系统保留。");
        }
    }
}
