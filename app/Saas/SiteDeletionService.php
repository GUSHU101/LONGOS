<?php

namespace App\Saas;

use App\Saas\Models\Site;
use App\Saas\Support\SensitiveValueRedactor;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Permanently removes a tenant without ever making a still-routable site
 * disappear from the control plane. Every cleanup step is retry-safe: after
 * publication succeeds the site remains in `deleting` until all owned data
 * and files have been removed.
 */
class SiteDeletionService
{
    public function __construct(
        private readonly DomainConfigWriter $domainConfig,
        private readonly SiteAuditLogger $audit,
        private readonly Filesystem $files,
    ) {}

    /** @return array{code: string, data_deleted: bool, database_mode: string} */
    public function delete(Site $site, bool $deleteData = true): array
    {
        return $this->domainConfig->synchronized(
            fn (): array => $this->deleteSynchronized($site, $deleteData)
        );
    }

    /** @return array{code: string, data_deleted: bool, database_mode: string} */
    private function deleteSynchronized(Site $site, bool $deleteData): array
    {
        $site = Site::query()->with('domains')->findOrFail($site->getKey());
        if ((bool) data_get($site->meta, 'control_site', false)) {
            throw new RuntimeException('控制站承载 SaaS 管理面，不允许删除。');
        }

        $previousStatus = $site->status;
        $previousMeta   = (array) $site->meta;
        $previousToken  = $site->provisioning_token;
        $previousBeat   = $site->provisioning_heartbeat_at;
        $newlyMarked    = $site->status !== Site::STATUS_DELETING;

        if ($newlyMarked) {
            $this->markDeleting($site);

            try {
                // The deleting status is deliberately outside the writer's
                // routable status set. Publish that fail-closed state before
                // any irreversible data operation begins.
                $this->domainConfig->sync();
            } catch (Throwable $exception) {
                Site::query()->whereKey($site->getKey())->update([
                    'status'                    => $previousStatus,
                    'meta'                      => $previousMeta,
                    'provisioning_token'        => $previousToken,
                    'provisioning_heartbeat_at' => $previousBeat,
                    'updated_at'                => now(),
                ]);
                $site->forceFill(['status' => $previousStatus, 'meta' => $previousMeta]);
                $this->audit->record($site, 'site.delete', 'failed', $exception->getMessage(), [
                    'phase' => 'unpublish',
                ]);

                throw $exception;
            }
        } else {
            // A retry after a partial failure also repairs a stale edge file.
            $this->domainConfig->sync();
        }

        $mode = $site->usesSharedDatabase() ? 'prefix' : 'database';
        $this->audit->record($site, 'site.delete', 'running', null, [
            'code'          => $site->code,
            'database_mode' => $mode,
            'delete_data'   => $deleteData,
        ]);

        try {
            if ($deleteData) {
                $site->usesSharedDatabase()
                    ? $this->dropPrefixedTenantData($site)
                    : $this->dropOwnedTenantDatabase($site);
                $this->deleteOwnedFiles($site);
            }

            $result = [
                'code'          => $site->code,
                'data_deleted'  => $deleteData,
                'database_mode' => $mode,
            ];

            $deletedSiteId = $site->getKey();
            DB::connection('saas')->transaction(function () use ($site): void {
                $locked = Site::query()->whereKey($site->getKey())->lockForUpdate()->first();
                if (! $locked) {
                    return;
                }
                if ((bool) data_get($locked->meta, 'control_site', false)) {
                    throw new RuntimeException('控制站不允许删除。');
                }
                if ($locked->status !== Site::STATUS_DELETING) {
                    throw new RuntimeException('站点删除状态已变更，已停止最终删除。');
                }
                $locked->delete();
            }, 3);
            $this->audit->record(null, 'site.delete', 'success', null, [
                ...$result,
                'deleted_site_id' => $deletedSiteId,
            ]);

            return $result;
        } catch (Throwable $exception) {
            $meta                          = (array) $site->meta;
            $meta['deletion_failed_at']    = now()->toIso8601String();
            $meta['deletion_last_error']   = SensitiveValueRedactor::redact($exception->getMessage());
            Site::query()->whereKey($site->getKey())->update([
                'status'                    => Site::STATUS_DELETING,
                'meta'                      => $meta,
                'provisioning_token'        => null,
                'provisioning_heartbeat_at' => null,
                'updated_at'                => now(),
            ]);
            $site->forceFill(['status' => Site::STATUS_DELETING, 'meta' => $meta]);
            $this->audit->record($site, 'site.delete', 'failed', $exception->getMessage(), [
                'phase' => 'cleanup',
            ]);

            throw $exception;
        }
    }

    private function markDeleting(Site $site): void
    {
        DB::connection('saas')->transaction(function () use ($site): void {
            $locked = Site::query()->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
            if ((bool) data_get($locked->meta, 'control_site', false)) {
                throw new RuntimeException('控制站不允许删除。');
            }

            $staleBefore = now()->subMinutes(max(5, (int) config('saas.provisioning.lease_timeout_minutes', 120)));
            if (is_string($locked->provisioning_token)
                && $locked->provisioning_token !== ''
                && $locked->provisioning_heartbeat_at?->gt($staleBefore)) {
                throw new RuntimeException('站点仍在执行建站任务，请等待建站完成或租约超时后再删除。');
            }

            $meta = (array) $locked->meta;
            unset($meta['deletion_last_error'], $meta['deletion_failed_at']);
            $meta['deletion_started_at'] = now()->toIso8601String();
            $locked->forceFill([
                'status'                    => Site::STATUS_DELETING,
                'meta'                      => $meta,
                'provisioning_token'        => null,
                'provisioning_heartbeat_at' => null,
            ])->save();

            $site->forceFill([
                'status'                    => Site::STATUS_DELETING,
                'meta'                      => $meta,
                'provisioning_token'        => null,
                'provisioning_heartbeat_at' => null,
            ]);
        }, 3);
    }

    private function dropPrefixedTenantData(Site $site): void
    {
        $prefix = $site->getDatabasePrefix();
        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,31}$/', $prefix)) {
            throw new RuntimeException('拒绝清理无效的租户表前缀。');
        }

        $collision = Site::query()
            ->whereKeyNot($site->getKey())
            ->get(['id', 'meta'])
            ->first(static function (Site $other) use ($prefix): bool {
                if (! $other->usesSharedDatabase()) {
                    return false;
                }

                $otherPrefix = (string) data_get($other->meta, 'database_prefix', '');

                return $otherPrefix !== ''
                    && (str_starts_with($prefix, $otherPrefix) || str_starts_with($otherPrefix, $prefix));
            });
        if ($collision) {
            throw new RuntimeException('租户表前缀与其他站点重复或存在包含关系，已拒绝删除数据。');
        }

        $config = (array) config('database.connections.tenant', []);
        if ($config === []) {
            throw new RuntimeException('未配置租户数据库连接。');
        }
        $config['database'] = $site->getDatabaseName();
        $config['prefix']   = '';
        $config['url']      = null;
        $config['name']     = 'longos_tenant_cleanup_' . $site->getKey();
        $connection         = DB::build($config);

        try {
            $driver = $connection->getDriverName();
            if (! in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
                throw new RuntimeException("共享库租户数据清理不支持驱动 {$driver}。");
            }

            $schema = $connection->getSchemaBuilder();
            $views  = $this->ownedNames($schema->getViews(), $prefix);
            $tables = $this->ownedNames($schema->getTableListing(null, false), $prefix);

            foreach ($views as $view) {
                $connection->statement('DROP VIEW IF EXISTS ' . $this->quoteIdentifier($view, $driver) . ($driver === 'pgsql' ? ' CASCADE' : ''));
            }

            if ($driver === 'mysql') {
                $connection->statement('SET FOREIGN_KEY_CHECKS=0');
            } elseif ($driver === 'sqlite') {
                $connection->statement('PRAGMA foreign_keys=OFF');
            }

            try {
                foreach ($tables as $table) {
                    $connection->statement('DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table, $driver) . ($driver === 'pgsql' ? ' CASCADE' : ''));
                }
            } finally {
                if ($driver === 'mysql') {
                    $connection->statement('SET FOREIGN_KEY_CHECKS=1');
                } elseif ($driver === 'sqlite') {
                    $connection->statement('PRAGMA foreign_keys=ON');
                }
            }

            $remaining = $this->ownedNames($schema->getTableListing(null, false), $prefix);
            if ($remaining !== []) {
                throw new RuntimeException('部分租户表未能删除：' . implode(', ', array_slice($remaining, 0, 5)));
            }
        } finally {
            $name = $connection->getName();
            $connection->disconnect();
            DB::purge($name);
        }
    }

    private function dropOwnedTenantDatabase(Site $site): void
    {
        $meta  = (array) $site->meta;
        $owned = (bool) ($meta['database_created'] ?? false)
            || is_array($meta['database_creation_pending'] ?? null);
        if (! $owned) {
            throw new RuntimeException('该独立数据库没有 LONGOS 创建标记，为避免误删外部数据已停止操作。');
        }

        $database = trim((string) $site->database_name);
        if (! preg_match('/^[a-zA-Z0-9_]{1,64}$/', $database)) {
            throw new RuntimeException('拒绝删除不安全的数据库名。');
        }

        $protected = array_filter(array_map(static fn ($value): string => strtolower(trim((string) $value)), [
            config('saas.central.database'),
            config('saas.shared_database'),
            config('database.connections.saas.database'),
            config('database.connections.tenant_admin.database'),
        ]));
        if (in_array(strtolower($database), $protected, true)) {
            throw new RuntimeException('租户数据库与控制库或共享库重合，已拒绝删除。');
        }

        $connection = DB::connection('tenant_admin');
        $driver     = $connection->getDriverName();
        if ($driver === 'mysql') {
            $connection->statement('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($database, $driver));
        } elseif ($driver === 'pgsql') {
            $connection->statement('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($database, $driver) . ' WITH (FORCE)');
        } else {
            throw new RuntimeException("独立租户数据库删除不支持驱动 {$driver}。");
        }
    }

    private function deleteOwnedFiles(Site $site): void
    {
        $targets = [];
        if ((bool) data_get($site->meta, 'isolate_files', config('saas.isolate_files', true))) {
            $targets[] = [public_path('image/catalog/tenants'), $site->code];
            $targets[] = [public_path('upload/tenants'), $site->code];
        }

        $sessionBase = $this->runtimeBasePath(
            (string) config('session.files', storage_path('framework/sessions')),
            storage_path('framework/sessions'),
        );
        $cacheBase = $this->runtimeBasePath(
            (string) config('cache.stores.file.path', storage_path('framework/cache/data')),
            storage_path('framework/cache/data'),
        );
        $targets[] = [$sessionBase . DIRECTORY_SEPARATOR . 'tenants', $site->code];
        $targets[] = [$cacheBase . DIRECTORY_SEPARATOR . 'tenants', (string) $site->getKey()];

        foreach ($targets as [$root, $segment]) {
            $this->deleteDirectoryInside((string) $root, (string) $segment);
        }
    }

    private function runtimeBasePath(string $configured, string $fallback): string
    {
        $configured = $configured !== '' ? $configured : $fallback;
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured);
        $marker     = DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR;
        $position   = strpos($normalized, $marker);

        return rtrim($position === false ? $normalized : substr($normalized, 0, $position), '/\\');
    }

    private function deleteDirectoryInside(string $root, string $segment): void
    {
        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $segment)) {
            throw new RuntimeException('拒绝清理不安全的租户文件路径。');
        }
        $target = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $segment;
        if (! file_exists($target) && ! is_link($target)) {
            return;
        }
        if (is_link($target)) {
            throw new RuntimeException("租户目录是符号链接，已拒绝自动删除：{$target}");
        }

        $realRoot   = realpath($root);
        $realTarget = realpath($target);
        if ($realRoot === false || $realTarget === false || ! $this->pathIsInside($realTarget, $realRoot)) {
            throw new RuntimeException("租户文件路径超出允许的隔离目录：{$target}");
        }
        if (! $this->files->deleteDirectory($realTarget)) {
            throw new RuntimeException("租户文件目录删除失败：{$target}");
        }
    }

    /** @return list<string> */
    private function ownedNames(array $entries, string $prefix): array
    {
        $names = [];
        foreach ($entries as $entry) {
            $name = is_array($entry)
                ? (string) ($entry['name'] ?? $entry['schema_qualified_name'] ?? '')
                : (string) $entry;
            $name = str_contains($name, '.') ? (string) substr($name, strrpos($name, '.') + 1) : $name;
            if ($name !== '' && str_starts_with($name, $prefix)) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    private function quoteIdentifier(string $identifier, string $driver): string
    {
        if (str_contains($identifier, "\0")) {
            throw new RuntimeException('数据库标识符无效。');
        }

        return $driver === 'pgsql'
            ? '"' . str_replace('"', '""', $identifier) . '"'
            : '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function pathIsInside(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return $path === $root || str_starts_with($path, $root . '/');
    }
}
