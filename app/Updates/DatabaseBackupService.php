<?php

namespace App\Updates;

use App\Saas\Models\Site;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

class DatabaseBackupService
{
    /** @return array<int, array<string, mixed>> */
    public function backup(string $directory): array
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('无法创建数据库备份目录。');
        }

        $plan = [];
        foreach ($this->connections() as $name => $connection) {
            $driver   = (string) ($connection['driver'] ?? '');
            $database = (string) ($connection['database'] ?? '');
            $objects  = [];
            if ($database === '') {
                throw new RuntimeException("数据库连接 {$name} 未配置数据库名。");
            }

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $file    = $directory . DIRECTORY_SEPARATOR . $this->backupFileName($connection, $driver, $database);
                $objects = $this->listMysqlObjects($connection, $database);
                $this->dumpMysql($connection, $database, $file);
            } elseif ($driver === 'pgsql') {
                $file    = $directory . DIRECTORY_SEPARATOR . $this->backupFileName($connection, $driver, $database);
                $objects = $this->listPostgresObjects($connection, $database);
                $this->dumpPostgres($connection, $database, $file);
            } elseif ($driver === 'sqlite') {
                $file   = $directory . DIRECTORY_SEPARATOR . $this->backupFileName($connection, $driver, $database);
                $source = $database;
                if ($source === ':memory:' || ! is_file($source)) {
                    throw new RuntimeException("无法备份 SQLite 数据库：{$name}");
                }
                $this->backupSqlite($source, $file, $name);
            } else {
                throw new RuntimeException("自动更新不支持 {$driver} 数据库备份。");
            }

            if (! is_file($file) || filesize($file) < 1) {
                throw new RuntimeException("数据库备份为空：{$name}");
            }
            $this->syncFile($file, "数据库备份无法安全落盘：{$name}");
            @chmod($file, 0600);
            $sha256 = hash_file('sha256', $file);
            if (! is_string($sha256)) {
                throw new RuntimeException("无法计算数据库备份校验值：{$name}");
            }
            $plan[] = [
                'name'       => $name,
                'driver'     => $driver,
                'database'   => $database,
                'connection' => $this->serializableConnection($connection),
                'file'       => basename($file),
                'sha256'     => $sha256,
                'objects'    => $objects,
            ];
        }

        $persisted = array_map(static function (array $entry): array {
            unset($entry['connection']['password']);

            return $entry;
        }, $plan);
        $path = $directory . DIRECTORY_SEPARATOR . 'databases.json';
        $this->atomicWrite(
            $path,
            json_encode([
                'schema'         => 1,
                'database_count' => count($persisted),
                'databases'      => $persisted,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            '无法安全保存数据库恢复清单。',
        );

        return $plan;
    }

    /** @param array<int, array<string, mixed>> $plan */
    public function restore(string $directory, array $plan): void
    {
        foreach ($plan as $entry) {
            $file = $directory . DIRECTORY_SEPARATOR . basename((string) ($entry['file'] ?? ''));
            if (! is_file($file) || ! hash_equals((string) ($entry['sha256'] ?? ''), (string) hash_file('sha256', $file))) {
                throw new RuntimeException('数据库备份完整性校验失败，已停止自动恢复。');
            }
            $connection = (array) ($entry['connection'] ?? []);
            $database   = (string) ($entry['database'] ?? '');
            match ((string) ($entry['driver'] ?? '')) {
                'mysql', 'mariadb' => $this->restoreMysql($connection, $database, $file, $this->validateMysqlObjects($entry['objects'] ?? null)),
                'pgsql'  => $this->restorePostgres($connection, $database, $file, $this->validatePostgresObjects($entry['objects'] ?? null)),
                'sqlite' => $this->restoreSqlite($database, $file),
                default  => throw new RuntimeException('数据库备份驱动无效。'),
            };
        }
    }

    /** Restore an interrupted update without persisting database passwords. */
    public function restorePersisted(string $directory): void
    {
        $path = $directory . DIRECTORY_SEPARATOR . 'databases.json';
        if (! is_file($path) || filesize($path) < 2 || filesize($path) > 67108864) {
            throw new RuntimeException('数据库恢复清单不存在或大小异常。');
        }
        $document = json_decode((string) file_get_contents($path), true);
        if (! is_array($document) || $document === []) {
            throw new RuntimeException('数据库恢复清单格式无效。');
        }

        // Keep recovery compatible with the v2.0.0.36 list-only manifest. New
        // manifests carry an explicit count so a truncated but valid JSON file
        // can never silently omit one tenant database.
        if (array_is_list($document)) {
            $persisted     = $document;
            $declaredCount = count($document);
        } else {
            if (($document['schema'] ?? null) !== 1
                || ! is_int($document['database_count'] ?? null)
                || ! is_array($document['databases'] ?? null)) {
                throw new RuntimeException('数据库恢复清单格式无效。');
            }
            $persisted     = $document['databases'];
            $declaredCount = $document['database_count'];
        }
        if ($persisted === [] || $declaredCount !== count($persisted)) {
            throw new RuntimeException('数据库恢复清单数量不完整。');
        }

        // Do not query the possibly half-migrated sites table during recovery.
        // Tenant connection names are deterministically rebuilt from the
        // persisted pre-update database names and the trusted current tenant
        // connection template.
        $available = $this->connectionsForRecovery($persisted);
        $plan      = [];
        $seen      = [];
        foreach ($persisted as $entry) {
            if (! is_array($entry)
                || ! is_string($entry['name'] ?? null)
                || ! isset($available[$entry['name']])
                || isset($seen[$entry['name']])
                || ! is_string($entry['file'] ?? null)
                || basename($entry['file']) !== $entry['file']
                || ! preg_match('/^[a-f0-9]{64}$/', (string) ($entry['sha256'] ?? ''))
                || (in_array((string) ($entry['driver'] ?? ''), ['mysql', 'mariadb', 'pgsql'], true)
                    && ! is_array($entry['objects'] ?? null))) {
                throw new RuntimeException('数据库恢复清单包含无效记录。');
            }
            $connection           = $available[$entry['name']];
            $seen[$entry['name']] = true;
            $expected             = (array) ($entry['connection'] ?? []);
            $actual               = $this->serializableConnection($connection);
            unset($actual['password'], $expected['password']);
            if ($expected                              !== $actual
                || (string) ($entry['driver'] ?? '')   !== (string) ($connection['driver'] ?? '')
                || (string) ($entry['database'] ?? '') !== (string) ($connection['database'] ?? '')
                || (string) $entry['file']             !== $this->backupFileName(
                    $connection,
                    (string) $entry['driver'],
                    (string) $entry['database'],
                )) {
                throw new RuntimeException('数据库连接在更新中断后已变化，为避免恢复到错误数据库已停止。');
            }
            $entry['connection'] = $connection;
            $plan[]              = $entry;
        }

        $expectedNames = array_keys($available);
        $actualNames   = array_keys($seen);
        sort($expectedNames, SORT_STRING);
        sort($actualNames, SORT_STRING);
        if ($expectedNames !== $actualNames) {
            throw new RuntimeException('数据库恢复清单未完整覆盖当前主站和全部租户数据库。');
        }

        $this->restore($directory, $plan);
    }

    /**
     * @param array<int, mixed> $persisted
     * @return array<string, array<string, mixed>>
     */
    private function connectionsForRecovery(array $persisted): array
    {
        if (! config('saas.enabled')) {
            $name = (string) config('database.default');

            return [$name => $this->normalizeConnection((array) config("database.connections.{$name}"))];
        }

        $connections = [];
        $control     = $this->normalizeConnection((array) config('database.connections.saas'));
        $this->appendConnection($connections, 'saas', $control);
        if ((string) config('saas.database_mode', 'prefix') !== 'database') {
            return $connections;
        }

        $tenant = $this->normalizeConnection((array) config('database.connections.tenant'));
        foreach ($persisted as $entry) {
            if (! is_array($entry)
                || ! is_string($entry['name'] ?? null)
                || ! str_starts_with($entry['name'], 'tenant:')
                || ! is_string($entry['database'] ?? null)
                || $entry['database'] === ''
                || strlen($entry['database']) > 255
                || str_contains($entry['database'], "\0")) {
                continue;
            }
            $candidate             = $tenant;
            $candidate['database'] = $entry['database'];
            $this->appendConnection($connections, 'tenant:' . $entry['database'], $candidate);
        }

        return $connections;
    }

    /** @param array<string, mixed> $connection */
    private function backupFileName(array $connection, string $driver, string $database): string
    {
        $extension = match ($driver) {
            'mysql', 'mariadb' => '.sql.gz',
            'pgsql'             => '.dump',
            'sqlite'            => '.sqlite',
            default             => throw new RuntimeException('数据库备份驱动无效。'),
        };
        $identity = implode('|', [
            $driver,
            (string) ($connection['host'] ?? ''),
            (string) ($connection['port'] ?? ''),
            $database,
        ]);

        return hash('sha256', $identity) . $extension;
    }

    /** @return array<string, array<string, mixed>> */
    private function connections(): array
    {
        $connections = [];
        if (! config('saas.enabled')) {
            $name               = (string) config('database.default');
            $connections[$name] = $this->normalizeConnection((array) config("database.connections.{$name}"));

            return $connections;
        }

        $control = $this->normalizeConnection((array) config('database.connections.saas'));
        $this->appendConnection($connections, 'saas', $control);

        if ((string) config('saas.database_mode', 'prefix') === 'database') {
            $tenant = $this->normalizeConnection((array) config('database.connections.tenant'));
            foreach (Site::query()->whereNotNull('database_name')->pluck('database_name')->all() as $database) {
                $candidate             = $tenant;
                $candidate['database'] = (string) $database;
                $this->appendConnection($connections, 'tenant:' . $database, $candidate);
            }
        }

        return $connections;
    }

    /** @param array<string, mixed> $connection @return array<string, mixed> */
    private function normalizeConnection(array $connection): array
    {
        $url = trim((string) ($connection['url'] ?? ''));
        if ($url === '') {
            return $connection;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('数据库 URL 无法解析，为避免无备份更新已停止。');
        }

        $schemeMap = [
            'mysql'      => 'mysql',
            'mariadb'    => 'mariadb',
            'postgres'   => 'pgsql',
            'postgresql' => 'pgsql',
            'pgsql'      => 'pgsql',
        ];
        $scheme = strtolower((string) $parts['scheme']);
        if (! isset($schemeMap[$scheme])) {
            throw new RuntimeException('自动更新不支持当前数据库 URL 驱动。');
        }

        $connection['driver']   = $schemeMap[$scheme];
        $connection['host']     = rawurldecode((string) $parts['host']);
        $connection['port']     = (int) ($parts['port'] ?? ($connection['driver'] === 'pgsql' ? 5432 : 3306));
        $connection['database'] = rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/'));
        $connection['username'] = rawurldecode((string) ($parts['user'] ?? ''));
        $connection['password'] = rawurldecode((string) ($parts['pass'] ?? ''));

        return $connection;
    }

    /** @param array<string, array<string, mixed>> $connections @param array<string, mixed> $connection */
    private function appendConnection(array &$connections, string $name, array $connection): void
    {
        $key = implode('|', [
            (string) ($connection['driver'] ?? ''),
            (string) ($connection['host'] ?? ''),
            (string) ($connection['port'] ?? ''),
            (string) ($connection['database'] ?? ''),
            (string) ($connection['username'] ?? ''),
        ]);
        $connections[$name . ':' . hash('sha256', $key)] = $connection;
    }

    /** @param array<string, mixed> $connection */
    private function dumpMysql(array $connection, string $database, string $file): void
    {
        $binary  = $this->binary('mysqldump', (string) config('longos.updater.mysqldump_path', ''), [
            '/www/server/mysql/bin/mysqldump', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump',
        ]);
        $command = array_merge([$binary], $this->mysqlConnectionArguments($connection), [
            '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--hex-blob', '--routines', '--triggers',
            '--default-character-set=utf8mb4', $database,
        ]);

        $stream = @gzopen($file, 'wb6');
        if (! is_resource($stream)) {
            throw new RuntimeException('无法创建 MySQL 备份文件。');
        }
        $stderr         = '';
        $writeException = null;
        $closed         = false;

        try {
            $process = new Process($command, base_path(), $this->mysqlEnvironment($connection), null, $this->timeout());
            $process->run(function (string $type, string $buffer) use ($stream, &$stderr): void {
                if ($type === Process::OUT) {
                    $written = gzwrite($stream, $buffer);
                    if (! is_int($written) || $written !== strlen($buffer)) {
                        throw new RuntimeException('MySQL 备份压缩写入不完整。');
                    }
                } else {
                    $stderr = mb_substr($stderr . $buffer, -4000);
                }
            });
        } catch (\Throwable $exception) {
            $writeException = $exception;
        } finally {
            $closed = gzclose($stream);
        }
        if ($writeException !== null || ! $closed || ! isset($process) || ! $process->isSuccessful()) {
            @unlink($file);

            $detail = $writeException?->getMessage() ?: $stderr;

            throw new RuntimeException('MySQL 自动备份失败：' . $this->safeError($detail), 0, $writeException);
        }
    }

    /** @param array<string, mixed> $connection */
    private function restoreMysql(array $connection, string $database, string $file, array $objects): void
    {
        $binary = $this->binary('mysql', (string) config('longos.updater.mysql_path', ''), [
            '/www/server/mysql/bin/mysql', '/usr/bin/mysql', '/usr/local/bin/mysql',
        ]);
        $this->dropUnexpectedMysqlObjects($connection, $database, $objects, $binary);
        $input = @gzopen($file, 'rb');
        if (! is_resource($input)) {
            throw new RuntimeException('无法读取 MySQL 备份文件。');
        }

        try {
            $process = new Process(
                array_merge([$binary], $this->mysqlConnectionArguments($connection), ['--database=' . $database]),
                base_path(),
                $this->mysqlEnvironment($connection),
                $input,
                $this->timeout(),
            );
            $process->run();
        } finally {
            gzclose($input);
        }
        if (! $process->isSuccessful()) {
            throw new RuntimeException('MySQL 自动恢复失败：' . $this->safeError($process->getErrorOutput()));
        }
    }

    /** @param array<string, mixed> $connection @return array<string, string> */
    private function listMysqlObjects(array $connection, string $database): array
    {
        $binary = $this->binary('mysql', (string) config('longos.updater.mysql_path', ''), [
            '/www/server/mysql/bin/mysql', '/usr/bin/mysql', '/usr/local/bin/mysql',
        ]);
        $process = new Process(array_merge([$binary], $this->mysqlConnectionArguments($connection), [
            '--database=' . $database,
            '--batch',
            '--skip-column-names',
            '--execute=SHOW FULL TABLES',
        ]), base_path(), $this->mysqlEnvironment($connection), null, $this->timeout());
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('MySQL 结构清单读取失败：' . $this->safeError($process->getErrorOutput()));
        }

        $objects = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $columns = explode("\t", $line, 2);
            if (count($columns) !== 2 || $columns[0] === '' || strlen($columns[0]) > 512) {
                throw new RuntimeException('MySQL 结构清单包含无效记录。');
            }
            $type = strtoupper(trim($columns[1]));
            if (! in_array($type, ['BASE TABLE', 'VIEW'], true)) {
                continue;
            }
            $objects[$columns[0]] = $type;
        }
        ksort($objects, SORT_STRING);

        return $objects;
    }

    /** @param mixed $objects @return array<string, string> */
    private function validateMysqlObjects(mixed $objects): array
    {
        if (! is_array($objects)) {
            throw new RuntimeException('MySQL 恢复清单缺少原始结构。');
        }
        $validated = [];
        foreach ($objects as $name => $type) {
            if (! is_string($name) || $name === '' || strlen($name) > 512
                                   || ! is_string($type) || ! in_array($type, ['BASE TABLE', 'VIEW'], true)) {
                throw new RuntimeException('MySQL 恢复清单包含无效结构。');
            }
            $validated[$name] = $type;
        }

        return $validated;
    }

    /** @param array<string, mixed> $connection @param array<string, string> $expected */
    private function dropUnexpectedMysqlObjects(array $connection, string $database, array $expected, string $binary): void
    {
        $current = $this->listMysqlObjects($connection, $database);
        $extra   = array_diff_key($current, $expected);
        if ($extra === []) {
            return;
        }

        $statements = ['SET FOREIGN_KEY_CHECKS=0'];
        foreach (['VIEW', 'BASE TABLE'] as $type) {
            foreach ($extra as $name => $actualType) {
                if ($actualType !== $type) {
                    continue;
                }
                $identifier   = '`' . str_replace('`', '``', $name) . '`';
                $statements[] = ($type === 'VIEW' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . $identifier;
            }
        }
        $statements[] = 'SET FOREIGN_KEY_CHECKS=1';
        $process      = new Process(
            array_merge([$binary], $this->mysqlConnectionArguments($connection), ['--database=' . $database]),
            base_path(),
            $this->mysqlEnvironment($connection),
            implode(';', $statements) . ';',
            $this->timeout(),
        );
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('MySQL 回滚时无法清理失败迁移的残留表：' . $this->safeError($process->getErrorOutput()));
        }
    }

    /** @param array<string, mixed> $connection */
    private function dumpPostgres(array $connection, string $database, string $file): void
    {
        $binary  = $this->binary('pg_dump', (string) config('longos.updater.pg_dump_path', ''), ['/usr/bin/pg_dump', '/usr/local/bin/pg_dump']);
        $process = new Process(array_merge(
            [$binary, '--format=custom', '--no-owner', '--no-privileges', '--file=' . $file],
            $this->postgresArguments($connection),
            [$database],
        ), base_path(), $this->postgresEnvironment($connection), null, $this->timeout());
        $process->run();
        if (! $process->isSuccessful()) {
            @unlink($file);

            throw new RuntimeException('PostgreSQL 自动备份失败：' . $this->safeError($process->getErrorOutput()));
        }
    }

    /** @param array<string, mixed> $connection */
    private function restorePostgres(array $connection, string $database, string $file, array $objects): void
    {
        $this->dropUnexpectedPostgresObjects($connection, $database, $objects);
        $binary  = $this->binary('pg_restore', (string) config('longos.updater.pg_restore_path', ''), ['/usr/bin/pg_restore', '/usr/local/bin/pg_restore']);
        $process = new Process(array_merge(
            [$binary, '--clean', '--if-exists', '--exit-on-error', '--no-owner', '--no-privileges'],
            $this->postgresArguments($connection),
            ['--dbname=' . $database, $file],
        ), base_path(), $this->postgresEnvironment($connection), null, $this->timeout());
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('PostgreSQL 自动恢复失败：' . $this->safeError($process->getErrorOutput()));
        }
    }

    /** @param array<string, mixed> $connection @return array<int, array{schema:string,name:string,type:string}> */
    private function listPostgresObjects(array $connection, string $database): array
    {
        $binary = $this->binary('psql', (string) config('longos.updater.psql_path', ''), ['/usr/bin/psql', '/usr/local/bin/psql']);
        $sql    = <<<'SQL'
SELECT n.nspname, c.relname, c.relkind
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
  AND n.nspname NOT LIKE 'pg_toast%'
  AND c.relkind IN ('r', 'p', 'v', 'm', 'S', 'f')
ORDER BY n.nspname, c.relname, c.relkind
SQL;
        $process = new Process(array_merge(
            [$binary, '--tuples-only', '--no-align', '--field-separator=' . "\t"],
            $this->postgresArguments($connection),
            ['--dbname=' . $database, '--command=' . $sql],
        ), base_path(), $this->postgresEnvironment($connection), null, $this->timeout());
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('PostgreSQL 结构清单读取失败：' . $this->safeError($process->getErrorOutput()));
        }

        $objects = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $columns = explode("\t", $line, 3);
            if (count($columns) !== 3
                || $columns[0] === '' || strlen($columns[0]) > 255
                || $columns[1] === '' || strlen($columns[1]) > 255
                || ! in_array($columns[2], ['r', 'p', 'v', 'm', 'S', 'f'], true)) {
                throw new RuntimeException('PostgreSQL 结构清单包含无效记录。');
            }
            $objects[] = ['schema' => $columns[0], 'name' => $columns[1], 'type' => $columns[2]];
        }

        return $objects;
    }

    /** @param mixed $objects @return array<int, array{schema:string,name:string,type:string}> */
    private function validatePostgresObjects(mixed $objects): array
    {
        if (! is_array($objects)) {
            throw new RuntimeException('PostgreSQL 恢复清单缺少原始结构。');
        }
        $validated = [];
        $seen      = [];
        foreach ($objects as $object) {
            if (! is_array($object)
                || ! is_string($object['schema'] ?? null) || $object['schema'] === '' || strlen($object['schema']) > 255
                || ! is_string($object['name'] ?? null) || $object['name']     === '' || strlen($object['name'])   > 255
                || ! is_string($object['type'] ?? null) || ! in_array($object['type'], ['r', 'p', 'v', 'm', 'S', 'f'], true)) {
                throw new RuntimeException('PostgreSQL 恢复清单包含无效结构。');
            }
            $identity = $object['schema'] . "\0" . $object['name'] . "\0" . $object['type'];
            if (isset($seen[$identity])) {
                throw new RuntimeException('PostgreSQL 恢复清单包含重复结构。');
            }
            $seen[$identity] = true;
            $validated[]     = ['schema' => $object['schema'], 'name' => $object['name'], 'type' => $object['type']];
        }

        return $validated;
    }

    /** @param array<string, mixed> $connection @param array<int, array{schema:string,name:string,type:string}> $expected */
    private function dropUnexpectedPostgresObjects(array $connection, string $database, array $expected): void
    {
        $current     = $this->listPostgresObjects($connection, $database);
        $expectedMap = [];
        foreach ($expected as $object) {
            $expectedMap[json_encode($object, JSON_THROW_ON_ERROR)] = true;
        }
        $extra = array_values(array_filter(
            $current,
            static fn (array $object): bool => ! isset($expectedMap[json_encode($object, JSON_THROW_ON_ERROR)]),
        ));
        if ($extra === []) {
            return;
        }

        $dropFor = [
            'v' => 'DROP VIEW IF EXISTS ',
            'm' => 'DROP MATERIALIZED VIEW IF EXISTS ',
            'f' => 'DROP FOREIGN TABLE IF EXISTS ',
            'p' => 'DROP TABLE IF EXISTS ',
            'r' => 'DROP TABLE IF EXISTS ',
            'S' => 'DROP SEQUENCE IF EXISTS ',
        ];
        $statements = ['BEGIN'];
        foreach (array_keys($dropFor) as $type) {
            foreach ($extra as $object) {
                if ($object['type'] !== $type) {
                    continue;
                }
                $schema       = '"' . str_replace('"', '""', $object['schema']) . '"';
                $name         = '"' . str_replace('"', '""', $object['name']) . '"';
                $statements[] = $dropFor[$type] . $schema . '.' . $name . ' CASCADE';
            }
        }
        $statements[] = 'COMMIT';

        $binary  = $this->binary('psql', (string) config('longos.updater.psql_path', ''), ['/usr/bin/psql', '/usr/local/bin/psql']);
        $process = new Process(array_merge(
            [$binary, '--set=ON_ERROR_STOP=1'],
            $this->postgresArguments($connection),
            ['--dbname=' . $database, '--command=' . implode(';', $statements) . ';'],
        ), base_path(), $this->postgresEnvironment($connection), null, $this->timeout());
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('PostgreSQL 回滚时无法清理失败迁移的残留对象：' . $this->safeError($process->getErrorOutput()));
        }
    }

    private function restoreSqlite(string $database, string $file): void
    {
        $temporary = $database . '.longos-restore-' . bin2hex(random_bytes(6));

        try {
            $this->copyDurably($file, $temporary, 'SQLite 自动恢复写入失败。');
        } catch (\Throwable $exception) {
            @unlink($temporary);

            throw $exception;
        }
        // Close Laravel handles and remove the WAL sidecars from the failed
        // schema before swapping the snapshot. Replaying an old WAL over the
        // restored main file would silently reintroduce failed migrations.
        foreach (array_keys((array) config('database.connections', [])) as $connection) {
            DB::purge((string) $connection);
        }
        foreach ([$database . '-wal', $database . '-shm'] as $sidecar) {
            if (is_file($sidecar) && ! @unlink($sidecar)) {
                @unlink($temporary);

                throw new RuntimeException('SQLite 自动恢复无法清理旧 WAL 文件。');
            }
        }
        if (! @rename($temporary, $database)) {
            @unlink($temporary);

            throw new RuntimeException('SQLite 自动恢复原子替换失败。');
        }
    }

    private function backupSqlite(string $source, string $destination, string $name): void
    {
        try {
            $pdo = new \PDO('sqlite:' . $source, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $quoted = $pdo->quote($destination);
            if (! is_string($quoted) || $pdo->exec("VACUUM INTO {$quoted}") === false) {
                throw new RuntimeException('VACUUM INTO 未生成快照。');
            }
        } catch (\Throwable $exception) {
            @unlink($destination);

            throw new RuntimeException("SQLite 一致快照失败：{$name}：" . $this->safeError($exception->getMessage()), 0, $exception);
        }
    }

    private function copyDurably(string $source, string $destination, string $error): void
    {
        $input  = @fopen($source, 'rb');
        $output = @fopen($destination, 'xb');
        if (! is_resource($input) || ! is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($destination);

            throw new RuntimeException($error);
        }

        try {
            $written = stream_copy_to_stream($input, $output);
            if ($written !== filesize($source)
                || ! @fflush($output)
                || (function_exists('fsync') && ! @fsync($output))) {
                throw new RuntimeException($error);
            }
        } catch (\Throwable $exception) {
            fclose($input);
            fclose($output);
            @unlink($destination);

            throw $exception;
        }
        fclose($input);
        fclose($output);
        @chmod($destination, 0600);
    }

    private function syncFile(string $path, string $error): void
    {
        $handle = @fopen($path, 'ab');
        if (! is_resource($handle)) {
            throw new RuntimeException($error);
        }

        try {
            if (! @fflush($handle) || (function_exists('fsync') && ! @fsync($handle))) {
                throw new RuntimeException($error);
            }
        } finally {
            fclose($handle);
        }
    }

    private function atomicWrite(string $path, string $contents, string $error): void
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $handle    = @fopen($temporary, 'xb');
        if (! is_resource($handle)) {
            throw new RuntimeException($error);
        }

        try {
            $length = strlen($contents);
            $offset = 0;
            while ($offset < $length) {
                $written = @fwrite($handle, substr($contents, $offset));
                if (! is_int($written) || $written < 1) {
                    throw new RuntimeException($error);
                }
                $offset += $written;
            }
            if (! @fflush($handle) || (function_exists('fsync') && ! @fsync($handle))) {
                throw new RuntimeException($error);
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($temporary);

            throw $exception;
        }
        fclose($handle);
        @chmod($temporary, 0600);
        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException($error);
        }
        @chmod($path, 0600);
    }

    /** @param array<string, mixed> $connection @return array<int, string> */
    private function mysqlConnectionArguments(array $connection): array
    {
        $arguments = ['--user=' . (string) ($connection['username'] ?? '')];
        $socket    = trim((string) ($connection['unix_socket'] ?? ''));
        if ($socket !== '') {
            $arguments[] = '--socket=' . $socket;
        } else {
            $arguments[] = '--host=' . (string) ($connection['host'] ?? '127.0.0.1');
            $arguments[] = '--port=' . (string) ($connection['port'] ?? 3306);
        }

        return $arguments;
    }

    /** @param array<string, mixed> $connection @return array<int, string> */
    private function postgresArguments(array $connection): array
    {
        return [
            '--host=' . (string) ($connection['host'] ?? '127.0.0.1'),
            '--port=' . (string) ($connection['port'] ?? 5432),
            '--username=' . (string) ($connection['username'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $connection @return array<string, string> */
    private function mysqlEnvironment(array $connection): array
    {
        return ['MYSQL_PWD' => (string) ($connection['password'] ?? '')];
    }

    /** @param array<string, mixed> $connection @return array<string, string> */
    private function postgresEnvironment(array $connection): array
    {
        return ['PGPASSWORD' => (string) ($connection['password'] ?? '')];
    }

    /** @param array<string, mixed> $connection @return array<string, mixed> */
    private function serializableConnection(array $connection): array
    {
        return array_intersect_key($connection, array_flip([
            'driver', 'host', 'port', 'username', 'password', 'unix_socket', 'charset',
        ]));
    }

    /** @param array<int, string> $candidates */
    private function binary(string $name, string $configured, array $candidates): string
    {
        if ($configured !== '') {
            array_unshift($candidates, $configured);
        }
        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        $probe = new Process([$name, '--version'], base_path(), null, null, 10);

        try {
            $probe->run();
            if ($probe->isSuccessful()) {
                return $name;
            }
        } catch (\Throwable) {
        }

        throw new RuntimeException("服务器缺少 {$name}，为避免无备份升级，更新已停止。");
    }

    private function timeout(): int
    {
        return max(60, (int) config('longos.updater.command_timeout', 3600));
    }

    private function safeError(string $message): string
    {
        $message = preg_replace('/(password|token|secret)\s*[=:]\s*\S+/i', '$1=[hidden]', $message) ?? '';

        return mb_substr(trim($message), 0, 1000) ?: '命令执行失败';
    }
}
