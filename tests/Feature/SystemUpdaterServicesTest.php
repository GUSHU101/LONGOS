<?php

namespace Tests\Feature;

use App\Updates\DatabaseBackupService;
use App\Updates\GitHubReleaseClient;
use App\Updates\UpdateManager;
use App\Updates\UpdateSettingsStore;
use App\Updates\UpdateStateStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class SystemUpdaterServicesTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporary = sys_get_temp_dir() . '/longos-updater-services-' . bin2hex(random_bytes(8));
        foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
            mkdir($this->temporary . '/' . $directory, 0700, true);
        }
        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->useStoragePath($this->temporary);
        $app->make(Kernel::class)->bootstrap();
        config([
            // These service tests exercise the legacy/manual updater in
            // isolation. Production keeps it disabled because Git/Coolify
            // owns releases and rollback.
            'longos.updater.enabled'       => true,
            'longos.updater.repository'    => 'GUSHU101/LONGOS',
            'longos.updater.channel'       => 'stable',
            'longos.updater.asset_name'    => 'longos-release.zip',
            'longos.updater.maximum_bytes' => 10485760,
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporary);
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_coolify_immutable_mode_blocks_every_file_update_entry_point(): void
    {
        config(['longos.updater.enabled' => false]);
        $manager = app(UpdateManager::class);

        foreach ([
            static fn () => $manager->check(),
            static fn () => $manager->queue(),
            static fn () => $manager->recover((string) Str::uuid()),
        ] as $operation) {
            try {
                $operation();
                self::fail('Immutable Coolify mode must reject application-level file updates.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Coolify 不可变镜像', $exception->getMessage());
            }
        }

        $manager->run((string) Str::uuid());
        self::assertSame('idle', (new UpdateStateStore)->get()['status']);
    }

    public function test_github_token_is_encrypted_and_release_digest_is_required(): void
    {
        $settings = new UpdateSettingsStore;
        $saved    = $settings->save('GUSHU101/LONGOS', 'stable', 'github_pat_read_only');
        self::assertTrue($saved['has_token']);
        self::assertSame('github_pat_read_only', $saved['token']);

        $settingsFile = $this->temporary . '/app/private/longos-updater/settings.json';
        self::assertFileExists($settingsFile);
        self::assertStringNotContainsString('github_pat_read_only', (string) file_get_contents($settingsFile));

        $invalidPayload = $this->releasePayload();
        unset($invalidPayload['assets'][0]['digest']);
        Http::fake([
            'api.github.com/repos/GUSHU101/LONGOS/releases/latest' => Http::sequence()
                ->push($this->releasePayload(), 200)
                ->push($invalidPayload, 200),
        ]);
        $release = (new GitHubReleaseClient($settings))->latest();
        self::assertSame('2.0.0.36', $release['version']);
        self::assertSame(str_repeat('a', 64), $release['asset']['sha256']);
        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer github_pat_read_only'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SHA-256');
        (new GitHubReleaseClient($settings))->latest();
    }

    public function test_github_repository_and_asset_url_cannot_escape_the_configured_repository(): void
    {
        $settings = new UpdateSettingsStore;

        try {
            $settings->save('GUSHU101/..', 'stable', null);
            self::fail('A dot-segment repository should be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }

        $settings->save('gushu101/longos', 'stable', null);
        $payload                          = $this->releasePayload();
        $wrongPayload                     = $payload;
        $wrongPayload['assets'][0]['url'] = 'https://api.github.com/repos/attacker/repository/releases/assets/360';
        Http::fake([
            'api.github.com/repos/gushu101/longos/releases/latest' => Http::sequence()
                ->push($payload)
                ->push($wrongPayload),
        ]);
        self::assertSame('2.0.0.36', (new GitHubReleaseClient($settings))->latest()['version']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('不匹配');
        (new GitHubReleaseClient($settings))->latest();
    }

    public function test_update_lock_allows_only_one_process(): void
    {
        $states = new UpdateStateStore;
        $lock   = $states->acquireLock();

        try {
            self::assertTrue($states->workerActive());
            $this->expectException(RuntimeException::class);
            $states->acquireLock();
        } finally {
            $states->releaseLock($lock);
        }
    }

    public function test_update_lock_reports_an_inactive_worker_after_release(): void
    {
        $states = new UpdateStateStore;
        $lock   = $states->acquireLock();
        $states->releaseLock($lock);

        self::assertFalse($states->workerActive());
    }

    public function test_an_orphaned_pending_update_can_be_released_without_touching_the_site(): void
    {
        $states = new UpdateStateStore;
        $jobId  = (string) Str::uuid();
        $states->put([
            'id'         => $jobId,
            'status'     => 'pending',
            'phase'      => 'queued',
            'progress'   => 1,
            'message'    => 'queued',
            'created_at' => now()->subMinutes(2)->toIso8601String(),
            'logs'       => [],
        ]);
        $statePath           = $this->temporary . '/app/private/longos-updater/state.json';
        $state               = json_decode((string) file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
        $state['updated_at'] = now()->subMinutes(2)->toIso8601String();
        file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));

        $recovered = app(UpdateManager::class)->recover($jobId);

        self::assertSame('failed', $recovered['status']);
        self::assertStringContainsString('未修改站点', $recovered['message']);
        self::assertFalse(app()->isDownForMaintenance());
    }

    public function test_a_fresh_update_cannot_be_marked_failed_by_an_early_recovery_request(): void
    {
        $states = new UpdateStateStore;
        $jobId  = (string) Str::uuid();
        $states->put([
            'id'         => $jobId,
            'status'     => 'running',
            'phase'      => 'download',
            'progress'   => 8,
            'message'    => 'downloading',
            'created_at' => now()->toIso8601String(),
            'logs'       => [],
        ]);

        try {
            app(UpdateManager::class)->recover($jobId);
            self::fail('A fresh update should not be recoverable.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('刚启动', $exception->getMessage());
        }

        $state = $states->get();
        self::assertSame('running', $state['status']);
        self::assertSame('download', $state['phase']);
    }

    public function test_an_interrupted_pre_mutation_phase_is_released_without_requiring_backups(): void
    {
        $states = new UpdateStateStore;
        $jobId  = (string) Str::uuid();
        $states->put([
            'id'                     => $jobId,
            'status'                 => 'running',
            'phase'                  => 'database_backup',
            'progress'               => 42,
            'message'                => 'backup',
            'maintenance_was_active' => false,
            'created_at'             => now()->subMinutes(2)->toIso8601String(),
            'logs'                   => [],
        ]);
        $this->ageStateFile();

        $recovered = app(UpdateManager::class)->recover($jobId);

        self::assertSame('rolled_back', $recovered['status']);
        self::assertStringContainsString('站点未被修改', $recovered['message']);
        self::assertFalse(app()->isDownForMaintenance());
    }

    public function test_an_incomplete_failed_recovery_blocks_new_updates_and_can_be_retried_immediately(): void
    {
        $states = new UpdateStateStore;
        $jobId  = (string) Str::uuid();
        $states->put([
            'id'                     => $jobId,
            'status'                 => 'failed',
            'phase'                  => 'failed',
            'recovery_phase'         => 'database_backup',
            'recovery_required'      => true,
            'progress'               => 42,
            'message'                => 'recovery failed',
            'maintenance_was_active' => false,
            'created_at'             => now()->toIso8601String(),
            'logs'                   => [],
        ]);

        try {
            app(UpdateManager::class)->queue();
            self::fail('A new update must not replace an incomplete recovery state.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('尚未完整恢复', $exception->getMessage());
        }

        $recovered = app(UpdateManager::class)->recover($jobId);
        self::assertSame('rolled_back', $recovered['status']);
        self::assertFalse($recovered['recovery_required']);
    }

    public function test_database_url_credentials_are_normalized_without_command_line_passwords(): void
    {
        $method = new ReflectionMethod(DatabaseBackupService::class, 'normalizeConnection');
        $result = $method->invoke(new DatabaseBackupService, [
            'url' => 'mariadb://longos:p%40ss@db.internal:3307/tenant_main',
        ]);

        self::assertSame('mariadb', $result['driver']);
        self::assertSame('db.internal', $result['host']);
        self::assertSame(3307, $result['port']);
        self::assertSame('tenant_main', $result['database']);
        self::assertSame('longos', $result['username']);
        self::assertSame('p@ss', $result['password']);
    }

    public function test_sqlite_backup_and_restore_are_wal_safe_and_exact(): void
    {
        $database = $this->temporary . '/source.sqlite';
        $backup   = $this->temporary . '/snapshot.sqlite';
        $pdo      = new \PDO('sqlite:' . $database);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('CREATE TABLE examples (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $pdo->exec("INSERT INTO examples (name) VALUES ('before')");

        $service = new DatabaseBackupService;
        $create  = new ReflectionMethod($service, 'backupSqlite');
        $create->invoke($service, $database, $backup, 'sqlite-test');
        $pdo->exec("INSERT INTO examples (name) VALUES ('after')");
        unset($pdo);

        $restore = new ReflectionMethod($service, 'restoreSqlite');
        $restore->invoke($service, $database, $backup);

        $restored = new \PDO('sqlite:' . $database);
        self::assertSame(['before'], $restored->query('SELECT name FROM examples ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function test_persisted_database_manifest_is_complete_and_restores_every_connection(): void
    {
        $database = $this->temporary . '/persisted.sqlite';
        $backup   = $this->temporary . '/database-backup';
        $pdo      = new \PDO('sqlite:' . $database);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE examples (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $pdo->exec("INSERT INTO examples (name) VALUES ('before')");
        unset($pdo);

        config([
            'saas.enabled'                              => false,
            'database.default'                          => 'updater_test',
            'database.connections.updater_test'         => [
                'driver'                  => 'sqlite',
                'database'                => $database,
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        $service = new DatabaseBackupService;
        $service->backup($backup);
        $manifest = json_decode((string) file_get_contents($backup . '/databases.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $manifest['schema']);
        self::assertSame(1, $manifest['database_count']);
        self::assertCount(1, $manifest['databases']);

        $pdo = new \PDO('sqlite:' . $database);
        $pdo->exec("INSERT INTO examples (name) VALUES ('after')");
        unset($pdo);
        $service->restorePersisted($backup);

        $restored = new \PDO('sqlite:' . $database);
        self::assertSame(['before'], $restored->query('SELECT name FROM examples ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        unset($restored);

        $manifest['databases'][]    = $manifest['databases'][0];
        $manifest['database_count'] = 2;
        file_put_contents($backup . '/databases.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('无效记录');
        $service->restorePersisted($backup);
    }

    public function test_recovery_rebuilds_tenant_connections_without_querying_a_migrated_sites_table(): void
    {
        $control = [
            'driver'   => 'mysql',
            'host'     => 'control.internal',
            'port'     => 3306,
            'database' => 'longos_control',
            'username' => 'control_user',
            'password' => 'secret',
        ];
        $tenant = [
            'driver'   => 'mysql',
            'host'     => 'tenant.internal',
            'port'     => 3306,
            'database' => '',
            'username' => 'tenant_user',
            'password' => 'secret',
        ];
        $database   = 'tenant_one';
        $tenantHash = hash('sha256', implode('|', ['mysql', 'tenant.internal', '3306', $database, 'tenant_user']));
        config([
            'saas.enabled'                    => true,
            'saas.database_mode'              => 'database',
            'database.connections.saas'       => $control,
            'database.connections.tenant'     => $tenant,
        ]);

        $method      = new ReflectionMethod(DatabaseBackupService::class, 'connectionsForRecovery');
        $connections = $method->invoke(new DatabaseBackupService, [[
            'name'     => 'tenant:' . $database . ':' . $tenantHash,
            'database' => $database,
        ]]);

        self::assertCount(2, $connections);
        self::assertArrayHasKey('tenant:' . $database . ':' . $tenantHash, $connections);
        self::assertSame($database, $connections['tenant:' . $database . ':' . $tenantHash]['database']);
    }

    /** @return array<string, mixed> */
    private function releasePayload(): array
    {
        return [
            'id'           => 36,
            'tag_name'     => 'v2.0.0.36',
            'name'         => 'LONGOS 2.0.0.36',
            'body'         => 'Verified release',
            'draft'        => false,
            'prerelease'   => false,
            'immutable'    => true,
            'published_at' => '2026-10-18T00:00:00Z',
            'html_url'     => 'https://github.com/GUSHU101/LONGOS/releases/tag/v2.0.0.36',
            'assets'       => [[
                'id'     => 360,
                'name'   => 'longos-release.zip',
                'state'  => 'uploaded',
                'size'   => 1024,
                'digest' => 'sha256:' . str_repeat('a', 64),
                'url'    => 'https://api.github.com/repos/GUSHU101/LONGOS/releases/assets/360',
            ]],
        ];
    }

    private function ageStateFile(): void
    {
        $statePath           = $this->temporary . '/app/private/longos-updater/state.json';
        $state               = json_decode((string) file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
        $state['updated_at'] = now()->subMinutes(2)->toIso8601String();
        file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
