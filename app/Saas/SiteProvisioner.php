<?php

namespace App\Saas;

use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\Support\DatabaseReadiness;
use App\Saas\Support\DomainName;
use App\Saas\Support\ExistingTenantDatabase;
use App\Saas\Support\IdempotencyConflict;
use App\Saas\Support\ProvisioningLeaseConflict;
use App\Saas\Support\SensitiveValueRedactor;
use App\Saas\Support\TenantDatabaseProvisioningState;
use App\Services\BundledPluginSynchronizer;
use Beike\Models\AdminUser;
use Beike\Models\Setting;
use Beike\Repositories\PluginRepo;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SiteProvisioner
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly DomainConfigWriter $domainConfig,
        private readonly SiteAuditLogger $audit,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $definitions
     * @return array<int, array<string, mixed>>
     */
    public function provisionMany(array $definitions, bool $migrate = true, bool $seed = true, ?callable $heartbeat = null): array
    {
        $maximum = max(1, (int) config('saas.max_batch_size', 100));
        if (count($definitions) > $maximum) {
            throw new RuntimeException("单次最多创建 {$maximum} 个站点。");
        }
        $this->assertUniqueBatch($definitions);
        $this->assertPlatformBatchDomains($definitions);

        // Coordinate long database migrations with domain changes and the
        // fail-closed release upgrader. Production multi-node deployments
        // must place this lock on the documented shared filesystem.
        return $this->domainConfig->synchronized(
            fn (): array => $this->provisionManySynchronized($definitions, $migrate, $seed, $heartbeat)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     * @return array<int, array<string, mixed>>
     */
    private function provisionManySynchronized(array $definitions, bool $migrate, bool $seed, ?callable $heartbeat): array
    {

        $results = [];
        foreach ($definitions as $definition) {
            $heartbeat?->__invoke();
            $results[] = $this->provision($definition, $migrate, $seed, $heartbeat);
            $heartbeat?->__invoke();
            gc_collect_cycles();
        }

        // Sites remain non-routable in READY until the complete batch has
        // been written to the web-server configuration. ACTIVATING domains
        // may be published by the writer, but TenantFinder only serves ACTIVE
        // sites. This removes the crash window where an unfinished site could
        // receive storefront traffic through wildcard DNS.
        $this->domainConfig->synchronized(function () use (&$results): void {
            $published = false;
            $ready     = array_filter(
                $results,
                static fn (array $result): bool => ($result['ok'] ?? false)
                    && ($result['status'] ?? null) === Site::STATUS_READY
                    && isset($result['_provisioning_token']),
            );
            if ($ready === []) {
                return;
            }

            try {
                DB::connection('saas')->transaction(function () use ($ready): void {
                    foreach ($ready as $result) {
                        $site = Site::query()->lockForUpdate()->findOrFail($result['site_id']);
                        if ($site->status !== Site::STATUS_READY
                            || ! is_string($site->provisioning_token)
                            || ! hash_equals($site->provisioning_token, $result['_provisioning_token'])) {
                            throw new ProvisioningLeaseConflict("站点 {$site->code} 的发布租约已变更。");
                        }
                        $site->forceFill([
                            'status'                    => Site::STATUS_ACTIVATING,
                            'provisioning_heartbeat_at' => now(),
                        ])->save();
                    }
                });

                $this->domainConfig->sync();
                $published = true;

                DB::connection('saas')->transaction(function () use ($ready): void {
                    foreach ($ready as $result) {
                        $updated = Site::query()
                            ->whereKey($result['site_id'])
                            ->where('status', Site::STATUS_ACTIVATING)
                            ->where('provisioning_token', $result['_provisioning_token'])
                            ->update([
                                'status'                    => Site::STATUS_ACTIVE,
                                'provisioning_token'        => null,
                                'provisioning_heartbeat_at' => null,
                                'updated_at'                => now(),
                            ]);
                        if ($updated !== 1) {
                            throw new ProvisioningLeaseConflict('域名已发布，但站点激活租约已失效。');
                        }
                        Domain::query()
                            ->where('site_id', $result['site_id'])
                            ->where('status', Domain::STATUS_ACTIVE)
                            ->update([
                                'published_at'       => now(),
                                'publication_error'  => null,
                                'updated_at'         => now(),
                            ]);
                    }
                });

                foreach ($results as &$result) {
                    if (($result['status'] ?? null) !== Site::STATUS_READY) {
                        continue;
                    }
                    $result['status'] = Site::STATUS_ACTIVE;
                    $site             = Site::query()->find($result['site_id']);
                    $this->audit->record($site, 'provision.published', 'success');
                }
                unset($result);
            } catch (Throwable $exception) {
                $safeMessage = SensitiveValueRedactor::redact($exception->getMessage());
                foreach ($results as &$result) {
                    if (! ($result['ok'] ?? false) || ! isset($result['_provisioning_token'])) {
                        continue;
                    }

                    $site = Site::query()->find($result['site_id']);
                    $this->audit->record($site, 'domain.sync', 'failed', $safeMessage);
                    if ($site && hash_equals((string) $site->provisioning_token, $result['_provisioning_token'])) {
                        $meta               = (array) $site->meta;
                        $meta['last_error'] = Str::limit($safeMessage, 2000, '');
                        $site->forceFill([
                            'status'                    => Site::STATUS_FAILED,
                            'meta'                      => $meta,
                            'provisioning_token'        => null,
                            'provisioning_heartbeat_at' => null,
                        ])->save();
                    }
                    $result['ok']     = false;
                    $result['status'] = Site::STATUS_FAILED;
                    $result['error']  = '站点数据已创建，但因域名配置同步失败而未上线：' . $safeMessage;
                }
                unset($result);

                if ($published) {
                    try {
                        // The web configuration was published but the final
                        // database activation failed. Rebuild from the
                        // fail-closed statuses so stale hosts are removed.
                        $this->domainConfig->sync();
                    } catch (Throwable $cleanupException) {
                        report($cleanupException);
                    }
                }
            }
        });

        foreach ($results as &$result) {
            unset($result['_provisioning_token']);
        }
        unset($result);

        return $results;
    }

    /** @return array<string, mixed> */
    public function provision(array $definition, bool $migrate = true, bool $seed = true, ?callable $heartbeat = null): array
    {
        $seed                  = $migrate && $seed;
        $site                  = null;
        $leaseToken            = (string) Str::uuid();
        $reuseExistingDatabase = false;
        $releaseMutex          = static function (): void {};

        try {
            // The heartbeat lease makes abandoned work reclaimable. A
            // database-session advisory lock additionally prevents another
            // node from reclaiming a live site while a single long migration
            // has not had an opportunity to refresh that heartbeat.
            $releaseMutex = $this->acquireProvisioningMutex($definition);
            $site         = DB::connection('saas')->transaction(function () use ($definition, $leaseToken, &$reuseExistingDatabase): Site {
                $site = Site::query()
                    ->where('code', $definition['code'])
                    ->orWhere('database_name', $definition['database_name'])
                    ->lockForUpdate()
                    ->first();

                if ($site && ($site->code !== $definition['code'] || $site->database_name !== $definition['database_name'])) {
                    throw new RuntimeException("站点 code 或数据库名已被占用：{$definition['code']}");
                }

                if ($site && ! in_array($site->status, [
                    Site::STATUS_FAILED,
                    Site::STATUS_PROVISIONING,
                    Site::STATUS_READY,
                    Site::STATUS_ACTIVATING,
                ], true)) {
                    throw new RuntimeException("站点 code 或数据库名已被占用：{$definition['code']}");
                }

                $staleBefore = now()->subMinutes(max(5, (int) config('saas.provisioning.lease_timeout_minutes', 120)));
                if ($site
                    && is_string($site->provisioning_token)
                    && $site->provisioning_token !== ''
                    && $site->provisioning_heartbeat_at?->gt($staleBefore)) {
                    throw new ProvisioningLeaseConflict("站点 {$definition['code']} 正在另一个建站任务中处理。");
                }

                if (! $site) {
                    $site = new Site(['uuid' => (string) Str::uuid()]);
                }
                $meta = array_merge(
                    ['isolate_files' => (bool) config('saas.isolate_files', true)],
                    (array) $site->meta,
                    $definition['meta'] ?? [],
                );
                if (($meta['database_mode'] ?? null) === 'database') {
                    [$meta, $reuseExistingDatabase] = TenantDatabaseProvisioningState::begin($meta, $leaseToken);
                }

                $site->fill([
                    'code'                      => $definition['code'],
                    'name'                      => $definition['name'],
                    'database_name'             => $definition['database_name'],
                    'status'                    => Site::STATUS_PROVISIONING,
                    'provisioning_token'        => $leaseToken,
                    'provisioning_heartbeat_at' => now(),
                    'locale'                    => $definition['locale'],
                    'timezone'                  => $definition['timezone'],
                    'meta'                      => $meta,
                ])->save();

                $site->domains()->delete();
                foreach ($definition['domains'] as $domain) {
                    $site->domains()->create([
                        'host'             => $domain['host'],
                        'scheme'           => $domain['scheme'],
                        'is_primary'       => $domain['is_primary'],
                        'routing_behavior' => Domain::ROUTING_REDIRECT,
                        'status'           => Domain::STATUS_ACTIVE,
                        'origin'           => Domain::ORIGIN_PLATFORM,
                        'verified_at'      => null,
                    ]);
                }

                return $site->load('domains');
            });
            $this->audit->record($site, 'provision.start', 'running', null, [
                'code'     => $site->code,
                'database' => $site->database_name,
                'domains'  => $site->domains->pluck('host')->all(),
            ]);
            $this->heartbeatProvisioning($site, $leaseToken, $heartbeat);

            if ($site->usesSharedDatabase()) {
                $created = false;
                $this->audit->record(
                    $site,
                    'provision.database',
                    'success',
                    '已分配共享数据库中的独立租户表命名空间。',
                    ['table_prefix' => $site->getDatabasePrefix()],
                );
            } else {
                try {
                    $created = $this->createDatabase($site->database_name, $reuseExistingDatabase);
                } catch (ExistingTenantDatabase $exception) {
                    // This was a known pre-existing database on the first
                    // attempt, not an interrupted LONGOS creation. Remove the
                    // intent marker so later retries cannot silently claim it.
                    $this->updateDatabaseProvisioningMeta(
                        $site,
                        $leaseToken,
                        TenantDatabaseProvisioningState::abort(...),
                    );

                    throw $exception;
                }
                $this->updateDatabaseProvisioningMeta(
                    $site,
                    $leaseToken,
                    TenantDatabaseProvisioningState::complete(...),
                );
                $this->ensureTenantDatabaseAccess($site->database_name);
                $this->audit->record($site, 'provision.database', 'success', $created ? '已创建数据库。' : '已复用上次由系统创建的数据库。');
            }
            $this->heartbeatProvisioning($site, $leaseToken, $heartbeat);

            $this->tenants->run($site, function () use ($site, $definition, $migrate, $seed, $heartbeat, $leaseToken): void {
                if ($migrate) {
                    $exitCode = Artisan::call('migrate', [
                        '--database'       => 'tenant',
                        '--force'          => true,
                        '--no-interaction' => true,
                    ]);
                    if ($exitCode !== 0) {
                        throw new RuntimeException(trim(Artisan::output()) ?: '站点数据库迁移失败。');
                    }
                    DatabaseReadiness::assertMigrated('tenant');
                    $this->audit->record($site, 'provision.migrate', 'success');
                    $this->heartbeatProvisioning($site, $leaseToken, $heartbeat);
                }

                if ($seed) {
                    $reusedSeedData = DatabaseReadiness::isSeeded('tenant');

                    if (! $reusedSeedData) {
                        // Seed a fresh or genuinely incomplete database. A
                        // retry that already has a usable base store skips
                        // this phase so plugin foreign keys and merchant data
                        // are never destroyed by legacy lookup seeders.
                        $exitCode = Artisan::call('db:seed', [
                            '--database'       => 'tenant',
                            '--force'          => true,
                            '--no-interaction' => true,
                        ]);
                        if ($exitCode !== 0) {
                            throw new RuntimeException(trim(Artisan::output()) ?: '站点初始数据写入失败。');
                        }
                        if (DB::connection('tenant')->getDriverName() === 'pgsql') {
                            $exitCode = Artisan::call('postgreSQL:sequence');
                            if ($exitCode !== 0) {
                                throw new RuntimeException(trim(Artisan::output()) ?: 'PostgreSQL 租户序列同步失败。');
                            }
                        }
                    }
                    if ($migrate) {
                        DB::connection('tenant')->transaction(function () use ($definition): void {
                            $this->configureSite($definition);
                        });
                        // Seeders register LONGOS' bundled defaults. Apply the
                        // migrations for those installed plugins before the
                        // tenant is marked ready, so its first web request can
                        // never reach an enabled plugin with missing tables.
                        $this->migrateInstalledPlugins();
                        DatabaseReadiness::assertSeeded('tenant', $definition['admin_email']);
                    }
                    $this->audit->record(
                        $site,
                        'provision.seed',
                        'success',
                        $reusedSeedData ? '检测到完整初始数据，已安全复用并修复站点配置。' : '已写入站点初始数据。',
                    );
                    $this->heartbeatProvisioning($site, $leaseToken, $heartbeat);
                } elseif ($migrate) {
                    $this->configureSite($definition);
                    DatabaseReadiness::assertAdministrator($definition['admin_email'], 'tenant');
                    $this->heartbeatProvisioning($site, $leaseToken, $heartbeat);
                }
            });

            $attributes = [
                'status'                    => $migrate ? Site::STATUS_READY : Site::STATUS_PROVISIONING,
                'provisioned_at'            => $migrate ? now() : null,
                'meta'                      => array_diff_key((array) $site->meta, ['last_error' => true]),
                'provisioning_heartbeat_at' => $migrate ? now() : null,
                'updated_at'                => now(),
            ];
            if (! $migrate) {
                $attributes['provisioning_token'] = null;
            }
            $updated = Site::query()
                ->whereKey($site->getKey())
                ->where('provisioning_token', $leaseToken)
                ->update($attributes);
            if ($updated !== 1) {
                throw new ProvisioningLeaseConflict("站点 {$site->code} 的建站租约已失效。");
            }
            $site->refresh();
            $this->audit->record($site, 'provision.complete', 'success', null, ['status' => $site->status]);

            return [
                'ok'                  => true,
                'status'              => $site->status,
                'site_id'             => $site->id,
                'code'                => $site->code,
                'name'                => $site->name,
                'database'            => $site->database_name,
                'url'                 => $site->primary_url,
                'admin_email'         => $definition['admin_email'],
                'admin_password'      => $definition['admin_password'],
                '_provisioning_token' => $migrate ? $leaseToken : null,
            ];
        } catch (IdempotencyConflict $exception) {
            throw $exception;
        } catch (ProvisioningLeaseConflict $exception) {
            $safeMessage = SensitiveValueRedactor::redact($exception->getMessage());
            $this->audit->record($site, 'provision.lease_conflict', 'failed', $safeMessage);

            return $this->failedResult($definition, $site, $safeMessage);
        } catch (Throwable $exception) {
            $safeMessage = SensitiveValueRedactor::redact($exception->getMessage());
            if ($site) {
                $meta               = (array) $site->meta;
                $meta['last_error'] = Str::limit($safeMessage, 2000, '');
                Site::query()
                    ->whereKey($site->getKey())
                    ->where('provisioning_token', $leaseToken)
                    ->update([
                        'status'                    => Site::STATUS_FAILED,
                        'meta'                      => $meta,
                        'provisioning_token'        => null,
                        'provisioning_heartbeat_at' => null,
                        'updated_at'                => now(),
                    ]);
            }
            $this->audit->record($site, 'provision.failed', 'failed', $safeMessage);

            return $this->failedResult($definition, $site, $safeMessage);
        } finally {
            $releaseMutex();
        }
    }

    private function migrateInstalledPlugins(): void
    {
        $bundled = app(BundledPluginSynchronizer::class);
        $bundled->synchronize();

        PluginRepo::resetRuntimeCache();
        $manager = app('plugin');
        $manager->forgetRuntimeCache();

        foreach ($manager->getPlugins() as $plugin) {
            if ($plugin->getInstalled()) {
                PluginRepo::migrateDatabase($plugin);
            }
        }

        $bundled->assertReady();
    }

    /** @return callable(): void */
    private function acquireProvisioningMutex(array $definition): callable
    {
        $identity   = (string) ($definition['code'] ?? '') . "\0" . (string) ($definition['database_name'] ?? '');
        $lockName   = 'longos:provision:' . substr(hash('sha256', $identity), 0, 40);
        $connection = DB::connection('saas');
        $driver     = $connection->getDriverName();

        if ($driver === 'mysql') {
            $row      = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
            $acquired = $this->databaseBoolean($row?->acquired ?? null);
            if (! $acquired) {
                throw new ProvisioningLeaseConflict('该站点正在另一个建站进程中处理。');
            }

            return static function () use ($connection, $lockName): void {
                try {
                    $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
                } catch (Throwable $exception) {
                    report($exception);
                }
            };
        }

        if ($driver === 'pgsql') {
            $row      = $connection->selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired', [$lockName]);
            $acquired = $this->databaseBoolean($row?->acquired ?? null);
            if (! $acquired) {
                throw new ProvisioningLeaseConflict('该站点正在另一个建站进程中处理。');
            }

            return static function () use ($connection, $lockName): void {
                try {
                    $connection->selectOne('SELECT pg_advisory_unlock(hashtextextended(?, 0)) AS released', [$lockName]);
                } catch (Throwable $exception) {
                    report($exception);
                }
            };
        }

        // SQLite is used for isolated tests and single-process local
        // development. Production provisioning only supports MySQL/PGSQL.
        return static function (): void {};
    }

    private function databaseBoolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private function heartbeatProvisioning(Site $site, string $leaseToken, ?callable $externalHeartbeat): void
    {
        $externalHeartbeat?->__invoke();

        Site::query()
            ->whereKey($site->getKey())
            ->where('provisioning_token', $leaseToken)
            ->whereIn('status', [Site::STATUS_PROVISIONING, Site::STATUS_READY, Site::STATUS_ACTIVATING])
            ->update([
                'provisioning_heartbeat_at' => now(),
                'updated_at'                => now(),
            ]);

        // MySQL can return zero for a successful same-second heartbeat when
        // both timestamp values are unchanged. Check the lease predicate
        // after the update so only a genuinely replaced/cleared token aborts
        // provisioning.
        $stillOwned = Site::query()
            ->whereKey($site->getKey())
            ->where('provisioning_token', $leaseToken)
            ->whereIn('status', [Site::STATUS_PROVISIONING, Site::STATUS_READY, Site::STATUS_ACTIVATING])
            ->exists();

        if (! $stillOwned) {
            throw new ProvisioningLeaseConflict("站点 {$site->code} 的建站租约已失效。");
        }
    }

    /** @return array<string, mixed> */
    private function failedResult(array $definition, ?Site $site, string $message): array
    {
        return [
            'ok'       => false,
            'site_id'  => $site?->id,
            'code'     => $site?->code          ?? $definition['code'] ?? '-',
            'name'     => $site?->name          ?? $definition['name'] ?? '-',
            'database' => $site?->database_name ?? $definition['database_name'] ?? '-',
            'url'      => $site?->primary_url   ?? ($definition['domains'][0]['host'] ?? null),
            'error'    => $message,
        ];
    }

    private function createDatabase(string $databaseName, bool $allowExisting = false): bool
    {
        if (! preg_match('/^[a-zA-Z0-9_]{1,64}$/', $databaseName)) {
            throw new RuntimeException('拒绝创建不安全的数据库名。');
        }

        $connection = DB::connection('tenant_admin');
        $driver     = $connection->getDriverName();

        if ($driver === 'mysql') {
            $exists = $connection->selectOne(
                'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
                [$databaseName]
            );
            if ($exists) {
                if ($allowExisting) {
                    return false;
                }

                throw new ExistingTenantDatabase("数据库 {$databaseName} 已存在，为避免覆盖数据已停止建站。");
            }
            $connection->statement("CREATE DATABASE `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            return true;
        }

        if ($driver === 'pgsql') {
            $exists = $connection->selectOne('SELECT 1 FROM pg_database WHERE datname = ?', [$databaseName]);
            if ($exists) {
                if ($allowExisting) {
                    return false;
                }

                throw new ExistingTenantDatabase("数据库 {$databaseName} 已存在，为避免覆盖数据已停止建站。");
            }
            $runtimeUser = $this->tenantRuntimeUsername();
            $connection->statement(
                'CREATE DATABASE "' . $databaseName . '" OWNER "' . str_replace('"', '""', $runtimeUser) . '" ENCODING \'UTF8\''
            );

            return true;
        }

        throw new RuntimeException("批量自动建库目前支持 MySQL 和 PostgreSQL，当前驱动为 {$driver}。");
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    private function updateDatabaseProvisioningMeta(
        Site $site,
        string $leaseToken,
        callable $mutator,
    ): void {
        $meta = DB::connection('saas')->transaction(function () use ($site, $leaseToken, $mutator): array {
            $locked = Site::query()
                ->whereKey($site->getKey())
                ->where('provisioning_token', $leaseToken)
                ->where('status', Site::STATUS_PROVISIONING)
                ->lockForUpdate()
                ->first();
            if (! $locked) {
                throw new ProvisioningLeaseConflict("站点 {$site->code} 的建库租约已失效。");
            }

            $meta = $mutator((array) $locked->meta);
            $locked->forceFill(['meta' => $meta])->save();

            return $meta;
        }, 3);

        // Keep the in-memory model synchronized so a later failure records
        // last_error without resurrecting an already-cleared intent marker.
        $site->forceFill(['meta' => $meta]);
    }

    private function ensureTenantDatabaseAccess(string $databaseName): void
    {
        $connection = DB::connection('tenant_admin');

        match ($connection->getDriverName()) {
            'mysql'  => $this->grantMysqlTenantAccess($connection, $databaseName),
            'pgsql'  => $this->assignPostgresTenantOwner($connection, $databaseName),
            'sqlite' => null,
            default  => throw new RuntimeException('无法为不受支持的数据库驱动配置租户运行权限。'),
        };
    }

    private function grantMysqlTenantAccess(Connection $connection, string $databaseName): void
    {
        $runtimeUser = $this->tenantRuntimeUsername();
        $grantHost   = trim((string) config('saas.tenant.grant_host', '%'));
        if ($grantHost === '' || str_contains($grantHost, "\0")) {
            throw new RuntimeException('SAAS_TENANT_DB_GRANT_HOST 配置无效。');
        }

        $adminUser = (string) $connection->getConfig('username');
        if ($adminUser === $runtimeUser && $this->mysqlHasGlobalTenantAccess($connection)) {
            return;
        }

        $account = $connection->getPdo()->quote($runtimeUser)
            . '@'
            . $connection->getPdo()->quote($grantHost);
        $privileges = implode(', ', [
            'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP',
            'REFERENCES', 'INDEX', 'ALTER', 'CREATE TEMPORARY TABLES',
            'LOCK TABLES', 'EXECUTE', 'CREATE VIEW', 'SHOW VIEW',
            'CREATE ROUTINE', 'ALTER ROUTINE', 'EVENT', 'TRIGGER',
        ]);

        try {
            $connection->statement("GRANT {$privileges} ON `{$databaseName}`.* TO {$account}");
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "租户数据库已创建，但 tenant_admin 无法向运行账号 {$runtimeUser}@{$grantHost} 授权；请确认账号已创建且建库账号拥有 GRANT OPTION。",
                0,
                $exception,
            );
        }
    }

    private function assignPostgresTenantOwner(Connection $connection, string $databaseName): void
    {
        $runtimeUser = str_replace('"', '""', $this->tenantRuntimeUsername());

        try {
            $connection->statement("ALTER DATABASE \"{$databaseName}\" OWNER TO \"{$runtimeUser}\"");
        } catch (Throwable $exception) {
            throw new RuntimeException(
                '租户数据库已创建，但 tenant_admin 无法把数据库所有权授予运行账号；请确认角色存在且建库账号可 SET ROLE。',
                0,
                $exception,
            );
        }
    }

    private function tenantRuntimeUsername(): string
    {
        $username = trim((string) config('database.connections.tenant.username', ''));
        if ($username === '' || str_contains($username, "\0")) {
            throw new RuntimeException('未配置有效的租户数据库运行账号。');
        }

        return $username;
    }

    private function mysqlHasGlobalTenantAccess(Connection $connection): bool
    {
        $required = [
            'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP',
            'REFERENCES', 'INDEX', 'ALTER', 'CREATE TEMPORARY TABLES',
            'LOCK TABLES', 'EXECUTE', 'CREATE VIEW', 'SHOW VIEW',
            'CREATE ROUTINE', 'ALTER ROUTINE', 'EVENT', 'TRIGGER',
        ];

        foreach ($connection->select('SHOW GRANTS FOR CURRENT_USER') as $row) {
            $grant = strtoupper(implode(' ', array_values((array) $row)));
            if (preg_match('/GRANT (.+) ON \\*\\.\\* TO /', $grant, $matches) !== 1) {
                continue;
            }
            if (str_contains($matches[1], 'ALL PRIVILEGES')) {
                return true;
            }

            $granted = array_map('trim', explode(',', $matches[1]));
            if (array_diff($required, $granted) === []) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array<string, mixed>> $definitions */
    private function assertUniqueBatch(array $definitions): void
    {
        $seen = [
            'code'     => [],
            'database' => [],
            'domain'   => [],
        ];

        foreach ($definitions as $definition) {
            $values = [
                'code'     => [(string) ($definition['code'] ?? '')],
                'database' => [(string) ($definition['database_name'] ?? '')],
                'domain'   => array_map(
                    static fn (array $domain): string => (string) ($domain['host'] ?? ''),
                    (array) ($definition['domains'] ?? []),
                ),
            ];

            foreach ($values as $type => $items) {
                foreach ($items as $value) {
                    if ($value === '') {
                        throw new RuntimeException("批量建站定义缺少 {$type}。");
                    }
                    if (isset($seen[$type][$value])) {
                        throw new RuntimeException("批量建站文件内存在重复 {$type}：{$value}");
                    }
                    $seen[$type][$value] = true;
                }
            }
        }
    }

    /** @param array<int, array<string, mixed>> $definitions */
    private function assertPlatformBatchDomains(array $definitions): void
    {
        $configured = trim((string) config('saas.base_domain', ''));
        if ($configured === '') {
            throw new RuntimeException('批量建站前必须配置 SAAS_BASE_DOMAIN。');
        }

        try {
            $baseDomain = DomainName::normalize($configured);
        } catch (Throwable $exception) {
            throw new RuntimeException('SAAS_BASE_DOMAIN 配置无效。', 0, $exception);
        }

        $controlHosts = [];
        foreach ((array) config('saas.control_domains', []) as $controlDomain) {
            try {
                $controlHosts[] = DomainName::normalize((string) $controlDomain);
            } catch (Throwable) {
                continue;
            }
        }
        $reserved = array_map('strtolower', (array) config('saas.domains.reserved_subdomains', []));

        foreach ($definitions as $definition) {
            foreach ((array) ($definition['domains'] ?? []) as $domain) {
                try {
                    $host = DomainName::normalize((string) ($domain['host'] ?? ''));
                } catch (Throwable $exception) {
                    throw new RuntimeException('批量建站包含无效的平台域名。', 0, $exception);
                }
                if ($host === $baseDomain || ! str_ends_with($host, '.' . $baseDomain)) {
                    throw new RuntimeException(
                        "批量建站不能把 {$host} 作为平台域名直接发布；仅允许 {$baseDomain} 下的子域名，自有域名必须完成 TXT 验证。"
                    );
                }
                foreach ($controlHosts as $controlHost) {
                    if ($host === $controlHost) {
                        throw new RuntimeException("控制站域名 {$controlHost} 不能分配给租户。");
                    }
                }
                $relative = substr($host, 0, -strlen('.' . $baseDomain));
                if (! str_contains($relative, '.') && in_array($relative, $reserved, true)) {
                    throw new RuntimeException("平台子域名 {$relative} 已被系统保留。");
                }
            }
        }
    }

    private function configureSite(array $definition): void
    {
        $settings = [
            'store_name' => $definition['name'],
            'meta_title' => $definition['name'],
            'email'      => $definition['admin_email'],
            'locale'     => $definition['locale'],
        ];

        foreach ($settings as $name => $value) {
            Setting::query()->updateOrCreate(
                ['type' => 'system', 'space' => 'base', 'name' => $name],
                ['value' => $value, 'json' => false]
            );
        }

        // LONGOS treats admin_users.id=1 as the tenant root administrator.
        // A failed provisioning attempt may already have created that row. If
        // a retry supplies a different email, updating by email would create a
        // second, non-root user and return unusable credentials to the caller.
        // Always configure the root row itself and fail closed when the target
        // email is already owned by another administrator.
        $root       = AdminUser::query()->find(1);
        $emailOwner = AdminUser::query()
            ->where('email', $definition['admin_email'])
            ->when($root, static fn ($query) => $query->whereKeyNot($root->getKey()))
            ->first();
        if ($emailOwner) {
            throw new RuntimeException('租户管理员邮箱已被其他后台账号占用，请更换邮箱后重试。');
        }

        $root ??= new AdminUser(['id' => 1]);
        $root->forceFill([
            'id'       => 1,
            'name'     => $definition['name'] . ' Admin',
            'email'    => $definition['admin_email'],
            'password' => bcrypt($definition['admin_password']),
            'locale'   => $definition['locale'],
            'active'   => true,
        ])->save();
    }
}
