<?php

namespace App\Saas;

use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

class DomainConfigWriter
{
    private int $lockDepth = 0;

    public function __construct(private readonly Filesystem $files) {}

    /**
     * @return array{config_path: string, manifest_path: string, hosts: int, reloaded: bool}
     */
    public function sync(): array
    {
        return $this->synchronized(fn (): array => $this->syncUnlocked());
    }

    /**
     * Serialize a complete control-plane domain mutation, including its
     * database rollback path. The lock is re-entrant for sync() calls made by
     * the same singleton service instance.
     */
    public function synchronized(callable $callback): mixed
    {
        if ($this->lockDepth > 0) {
            return $callback();
        }

        $lockPath = (string) config('saas.domain_config.manifest_path') . '.lock';
        $this->files->ensureDirectoryExists(dirname($lockPath));
        $lock = fopen($lockPath, 'c+');
        if ($lock === false) {
            throw new RuntimeException('无法获取域名配置写入锁。');
        }

        $timeout  = max(1, min(60, (int) config('saas.domain_config.lock_timeout_seconds', 10)));
        $deadline = microtime(true) + $timeout;
        while (! flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($lock);

                throw new RuntimeException("域名配置操作繁忙，等待 {$timeout} 秒后仍未取得锁。");
            }
            usleep(50_000);
        }

        try {
            $this->lockDepth++;

            return $callback();
        } finally {
            $this->lockDepth--;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array{config_path: string, manifest_path: string, hosts: int, reloaded: bool}
     */
    private function syncUnlocked(): array
    {
        $invalidSite = Site::query()
            ->select(['saas_sites.id', 'saas_sites.code'])
            ->leftJoin('saas_domains', function ($join): void {
                $join->on('saas_domains.site_id', '=', 'saas_sites.id')
                    ->whereIn('saas_domains.status', [Domain::STATUS_ACTIVE, Domain::STATUS_ACTIVATING])
                    ->where('saas_domains.is_primary', true);
            })
            ->whereIn('saas_sites.status', [Site::STATUS_ACTIVE, Site::STATUS_ACTIVATING])
            ->groupBy('saas_sites.id', 'saas_sites.code')
            ->havingRaw('COUNT(saas_domains.id) <> 1')
            ->first();
        if ($invalidSite) {
            throw new RuntimeException("站点 {$invalidSite->code} 必须且只能有一个已启用的主域名。");
        }

        $domains = Domain::query()
            ->with('site:id,code,name,status')
            ->whereIn('status', [Domain::STATUS_ACTIVE, Domain::STATUS_ACTIVATING])
            ->whereHas('site', static fn ($query) => $query->whereIn('status', [
                Site::STATUS_ACTIVE,
                Site::STATUS_ACTIVATING,
            ]))
            ->orderBy('host')
            ->get();

        $driver       = strtolower((string) config('saas.domain_config.driver', 'caddy'));
        $path         = (string) config('saas.domain_config.path');
        $manifestPath = (string) config('saas.domain_config.manifest_path');
        if ($path === '' || $manifestPath === '') {
            throw new RuntimeException('必须同时配置域名配置文件和映射清单路径。');
        }
        $normalizedPath         = strtolower(rtrim(str_replace('\\', '/', $path), '/'));
        $normalizedManifestPath = strtolower(rtrim(str_replace('\\', '/', $manifestPath), '/'));
        if ($normalizedPath === $normalizedManifestPath) {
            throw new RuntimeException('域名配置文件与映射清单不能使用同一路径。');
        }
        $hosts = $domains->pluck('host')->all();

        if ($driver !== 'caddy') {
            throw new RuntimeException("LONGOS 生产域名入口仅支持 Coolify Caddy；当前 SAAS_WEB_SERVER：{$driver}");
        }
        $content = $this->renderCaddy($hosts);

        $manifest = [
            'generated_at' => now()->toIso8601String(),
            'domains'      => $domains->map(static fn (Domain $domain): array => [
                'host'      => $domain->host,
                'scheme'    => $domain->scheme,
                'site_id'   => $domain->site_id,
                'site_code' => $domain->site->code,
                'primary'   => $domain->is_primary,
                'type'      => $domain->routing_type,
            ])->values()->all(),
        ];

        $previousConfig   = $this->snapshot($path);
        $previousManifest = $this->snapshot($manifestPath);

        try {
            $this->writeAtomically($path, $content);
            $this->writeAtomically($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
        } catch (Throwable $exception) {
            $rollbackErrors = $this->restoreSnapshots($path, $previousConfig, $manifestPath, $previousManifest);
            $suffix         = $rollbackErrors === [] ? '' : '；回滚也失败：' . implode(' | ', $rollbackErrors);

            throw new RuntimeException('域名配置写入失败，已尝试回滚：' . $exception->getMessage() . $suffix, 0, $exception);
        }

        return [
            'config_path'   => $path,
            'manifest_path' => $manifestPath,
            'hosts'         => count($hosts),
            // Coolify's caddy-docker-proxy already watches container labels.
            // Domain membership is authorized live by LONGOS's ask endpoint,
            // so application code never reloads a host service.
            'reloaded'      => false,
        ];
    }

    /** @param array<int, string> $hosts */
    public function renderCaddy(array $hosts): string
    {
        $lines = [
            '# Generated by LONGOS SaaS. Do not edit.',
            '# Caddy On-Demand TLS creates virtual hosts and certificates after',
            '# the LONGOS ask endpoint authorizes an active control-plane host.',
        ];
        foreach ($hosts as $host) {
            $lines[] = '# active: ' . $host;
        }
        if ($hosts === []) {
            $lines[] = '# No active SaaS domains.';
        }
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }

    private function writeAtomically(string $path, string $content): void
    {
        if ($path === '') {
            throw new RuntimeException('未配置域名配置文件路径。');
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        $this->files->put($temporary, $content, true);

        $backup      = $path . '.replace-backup-' . bin2hex(random_bytes(4));
        $hadOriginal = is_file($path);
        if ($hadOriginal && ! @rename($path, $backup)) {
            $this->files->delete($temporary);

            throw new RuntimeException("无法备份域名配置文件：{$path}");
        }

        if (! @rename($temporary, $path)) {
            if ($hadOriginal) {
                @rename($backup, $path);
            }
            $this->files->delete($temporary);

            throw new RuntimeException("无法替换域名配置文件：{$path}");
        }

        if ($hadOriginal) {
            $this->files->delete($backup);
        }
    }

    /** @return array{exists: bool, content: string} */
    private function snapshot(string $path): array
    {
        $exists  = is_file($path);
        $content = $exists ? file_get_contents($path) : '';
        if ($exists && $content === false) {
            throw new RuntimeException("无法读取待替换的域名配置文件：{$path}");
        }

        return [
            'exists'  => $exists,
            'content' => (string) $content,
        ];
    }

    /**
     * @param array{exists: bool, content: string} $config
     * @param array{exists: bool, content: string} $manifest
     * @return array<int, string>
     */
    private function restoreSnapshots(string $path, array $config, string $manifestPath, array $manifest): array
    {
        $errors = [];
        foreach ([[$path, $config], [$manifestPath, $manifest]] as [$target, $snapshot]) {
            try {
                $this->restore($target, $snapshot);
            } catch (Throwable $exception) {
                $errors[] = "{$target}: {$exception->getMessage()}";
            }
        }

        return $errors;
    }

    /** @param array{exists: bool, content: string} $snapshot */
    private function restore(string $path, array $snapshot): void
    {
        if ($snapshot['exists']) {
            $this->writeAtomically($path, $snapshot['content']);
        } else {
            $this->files->delete($path);
        }
    }
}
