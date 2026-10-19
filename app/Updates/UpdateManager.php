<?php

namespace App\Updates;

use App\Saas\Support\SensitiveValueRedactor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class UpdateManager
{
    public function __construct(
        private readonly GitHubReleaseClient $github,
        private readonly UpdateStateStore $states,
        private readonly ReleasePackage $packages,
        private readonly DatabaseBackupService $databases,
        private readonly UpdateLauncher $launcher,
    ) {}

    /** @return array<string, mixed> */
    public function check(): array
    {
        $this->assertEnabled();

        $release                     = $this->github->latest();
        $current                     = (string) config('beike.version', '0.0.0');
        $release['current_version']  = $current;
        $release['update_available'] = version_compare($release['version'], $current, '>');

        return $release;
    }

    /** @return array<string, mixed> */
    public function queue(?string $expectedVersion = null, ?int $expectedAssetId = null): array
    {
        $this->assertEnabled();

        $lock = $this->states->acquireLock();

        try {
            $currentState = $this->states->get();
            if (($currentState['status'] ?? null) === 'failed' && ($currentState['recovery_required'] ?? false) === true) {
                throw new RuntimeException('上一次更新尚未完整恢复，请先点击“恢复中断更新”。');
            }
            if (in_array($currentState['status'] ?? null, ['pending', 'running'], true)) {
                if (($currentState['status'] ?? null)   === 'pending'
                    && ($currentState['phase'] ?? null) === 'queued'
                    && $this->isStaleQueuedState($currentState)) {
                    $currentState['status']  = 'failed';
                    $currentState['phase']   = 'failed';
                    $currentState['message'] = '上一个更新未启动且已超时，已安全释放。';
                    $this->states->put($currentState);
                } else {
                    throw new RuntimeException('已有 LONGOS 更新正在运行；如状态长时间不变，请勿覆盖备份并先检查更新日志。');
                }
            }

            $release = $this->check();
            if (! ($release['update_available'] ?? false)) {
                throw new RuntimeException('当前已经是最新版本。');
            }
            if ($expectedVersion !== null && ! hash_equals($expectedVersion, (string) $release['version'])) {
                throw new RuntimeException('GitHub 发布在确认前已变化，请重新检查更新。');
            }
            if ($expectedAssetId !== null && $expectedAssetId !== (int) data_get($release, 'asset.id', 0)) {
                throw new RuntimeException('GitHub 发布资产在确认前已被替换，请重新检查更新。');
            }

            $jobId = (string) Str::uuid();
            $state = [
                'id'           => $jobId,
                'status'       => 'pending',
                'phase'        => 'queued',
                'progress'     => 1,
                'message'      => '更新已进入后台队列。',
                'from_version' => (string) $release['current_version'],
                'to_version'   => (string) $release['version'],
                'release'      => $release,
                'logs'         => [['time' => now()->toIso8601String(), 'message' => '已锁定 GitHub Release 元数据和 SHA-256。']],
                'created_at'   => now()->toIso8601String(),
            ];
            $this->states->put($state);

            return $state;
        } finally {
            $this->states->releaseLock($lock);
        }
    }

    public function run(string $jobId): void
    {
        // Coolify deployments use immutable images. A stale detached worker
        // must never modify a container after that runtime policy is enabled.
        if (! config('longos.updater.enabled', false)) {
            return;
        }

        @ignore_user_abort(true);
        @set_time_limit(0);

        try {
            $lock = $this->states->acquireLock();
        } catch (Throwable $exception) {
            // A duplicate detached/fallback launcher must not overwrite the
            // state owned by the process that already holds this lock.
            report($exception);

            return;
        }

        try {
            $state = $this->states->get();
            if (($state['id'] ?? null) !== $jobId || ($state['status'] ?? null) !== 'pending') {
                return;
            }

            $state['status']  = 'running';
            $state['message'] = '正在准备安全更新。';
            $this->states->put($state);
            $this->apply($state);
        } catch (Throwable $exception) {
            $state = $this->states->get();
            if (($state['id'] ?? null) === $jobId && ! in_array($state['status'] ?? null, ['completed', 'rolled_back'], true)) {
                $state['status']  = 'failed';
                $state['phase']   = 'failed';
                $state['message'] = '更新失败：' . SensitiveValueRedactor::redact($exception->getMessage());
                $this->appendLog($state, $state['message']);
                $this->states->put($state);
            }
            report($exception);
        } finally {
            $this->states->releaseLock($lock);
        }
    }

    /** Recover a worker that was terminated without executing its catch block. */
    public function recover(string $jobId): array
    {
        $this->assertEnabled();

        if (! Str::isUuid($jobId)) {
            throw new RuntimeException('中断更新编号无效。');
        }
        $lock            = $this->states->acquireLock();
        $recoveryStarted = false;
        $jobRoot         = storage_path('app/private/longos-updater/jobs/' . $jobId);

        try {
            $state                  = $this->states->get();
            $retryingFailedRecovery = ($state['status'] ?? null) === 'failed' && ($state['recovery_required'] ?? false) === true;
            if (($state['id'] ?? null) !== $jobId
                || (! in_array($state['status'] ?? null, ['pending', 'running'], true) && ! $retryingFailedRecovery)) {
                throw new RuntimeException('该更新不需要恢复。');
            }
            $updated = strtotime((string) ($state['updated_at'] ?? ''));
            if (! $retryingFailedRecovery && ($updated === false || $updated > time() - 30)) {
                throw new RuntimeException('更新刚启动，请稍后再检查。');
            }

            if (($state['status'] ?? null) === 'pending') {
                $state['status']  = 'failed';
                $state['phase']   = 'failed';
                $state['message'] = '后台更新进程未启动，未修改站点；现在可重新点击更新。';
                $this->appendLog($state, $state['message']);
                $this->states->put($state);
                $this->cleanupWorkingDirectory($jobRoot);

                return $state;
            }

            $wasDown = (bool) ($state['maintenance_was_active'] ?? false);
            $phase   = (string) ($state['recovery_phase'] ?? $state['phase'] ?? '');
            if (in_array($phase, ['queued', 'download', 'verify', 'maintenance', 'queue_drain', 'database_backup'], true)) {
                $recoveryStarted = true;
                if (! $wasDown && app()->isDownForMaintenance()) {
                    $this->runArtisanProcess(['up'], '更新未修改代码，但无法退出维护模式');
                }
                $state['status']            = 'rolled_back';
                $state['phase']             = 'rolled_back';
                $state['progress']          = 100;
                $state['message']           = '更新在代码和数据库变更前中断，站点未被修改。';
                $state['recovery_required'] = false;
                unset($state['recovery_phase']);
                $this->appendLog($state, $state['message']);
                $this->states->put($state);
                $this->cleanupWorkingDirectory($jobRoot);

                return $state;
            }

            $recoveryStarted = true;
            if (! app()->isDownForMaintenance()) {
                $exit = Artisan::call('down', ['--retry' => 60, '--secret' => Str::random(40)]);
                if ($exit !== 0) {
                    throw new RuntimeException('无法在恢复前进入维护模式。');
                }
            }

            $backupRoot   = (string) ($state['backup'] ?? '');
            $expectedRoot = storage_path('app/private/longos-updater/backups/' . $jobId);
            if ($backupRoot === '' || ! hash_equals($expectedRoot, $backupRoot)) {
                throw new RuntimeException('中断更新的备份路径无效。');
            }

            $codeBackup = $backupRoot . '/code';
            $ledger     = $this->packages->readLedger($codeBackup);
            if (is_array($ledger)) {
                $this->packages->rollback($codeBackup, $ledger);
                $this->appendLog($state, '已从落盘清单恢复中断前的代码。');
            } elseif (is_dir($codeBackup) || in_array($phase, ['migrate', 'verify_runtime'], true)) {
                throw new RuntimeException('中断更新的代码回滚清单不可用，已保持维护模式。');
            }
            if (in_array($phase, ['migrate', 'verify_runtime'], true)) {
                $this->databases->restorePersisted($backupRoot . '/databases');
                $this->appendLog($state, '已从一致快照恢复主站和租户数据库。');
            }
            $this->runArtisanProcess(['optimize:clear'], '中断恢复后无法清理缓存');
            if (! $wasDown) {
                $this->runArtisanProcess(['up'], '中断恢复完成但无法退出维护模式');
            }

            $state['status']            = 'rolled_back';
            $state['phase']             = 'rolled_back';
            $state['progress']          = 100;
            $state['message']           = '已自动恢复被系统中断的更新，原站点数据和代码已保留。';
            $state['recovery_required'] = false;
            unset($state['recovery_phase']);
            $this->appendLog($state, $state['message']);
            $this->states->put($state);
            $this->cleanupWorkingDirectory($jobRoot);

            return $state;
        } catch (Throwable $exception) {
            $state = $this->states->get();
            if ($recoveryStarted && ($state['id'] ?? null) === $jobId) {
                $state['status']            = 'failed';
                $state['phase']             = 'failed';
                $state['message']           = '中断更新未能完整恢复，站点已保持维护模式：' . SensitiveValueRedactor::redact($exception->getMessage());
                $state['recovery_required'] = true;
                $state['recovery_phase']    = $phase ?? (string) ($state['recovery_phase'] ?? '');
                $this->appendLog($state, $state['message']);
                $this->states->put($state);
            }

            throw $exception;
        } finally {
            $this->states->releaseLock($lock);
        }
    }

    /** @param array<string, mixed> $state */
    private function apply(array $state): void
    {
        $jobId                            = (string) $state['id'];
        $release                          = (array) $state['release'];
        $root                             = storage_path('app/private/longos-updater/jobs/' . $jobId);
        $archive                          = $root . '/longos-release.zip';
        $stage                            = $root . '/stage';
        $backupRoot                       = storage_path('app/private/longos-updater/backups/' . $jobId);
        $codeBackup                       = $backupRoot . '/code';
        $databasePath                     = $backupRoot . '/databases';
        $ledger                           = null;
        $databasePlan                     = [];
        $maintenance                      = false;
        $alreadyDown                      = app()->isDownForMaintenance();
        $migrationsStarted                = false;
        $codeApplyStarted                 = false;
        $state['backup']                  = $backupRoot;
        $state['maintenance_was_active']  = $alreadyDown;
        $this->states->put($state);

        try {
            $this->pruneBackups($jobId);
            $this->phase($state, 'download', 8, '正在从 GitHub 下载已锁定的发布包。');
            $this->github->download($release, $archive);

            $this->phase($state, 'verify', 22, '正在验证发布清单、文件哈希和安全路径。');
            $staged   = $this->packages->stage($archive, $stage, (string) $release['version']);
            $manifest = $staged['manifest'];
            $this->assertVersionTransition($state, $manifest);
            $this->assertWritable($manifest);
            $this->assertDiskSpace($archive, $manifest);

            if (! $alreadyDown) {
                $this->phase($state, 'maintenance', 32, '正在进入带恢复密钥的维护模式。');
                $exit = Artisan::call('down', [
                    '--retry'  => 60,
                    '--secret' => Str::random(40),
                ]);
                if ($exit !== 0) {
                    throw new RuntimeException('无法进入维护模式：' . trim(Artisan::output()));
                }
                $maintenance = true;
            }

            $this->phase($state, 'queue_drain', 37, '正在中断调度循环并等待在途请求、队列任务安全完成。');
            $this->quiesceRuntime();
            $this->drainQueueWorkers();

            // Freeze web writes before the snapshot. Otherwise a failed
            // migration could restore a database taken before a last-second
            // checkout and silently lose that order.
            $this->phase($state, 'database_backup', 42, '正在备份主站和租户数据库。');
            // Database snapshots are deliberately non-optional. A production
            // switch that can bypass them turns a convenient updater into a
            // potential order-loss path when a migration later fails.
            $databasePlan = $this->databases->backup($databasePath);

            $this->phase($state, 'code_backup', 52, '正在逐文件备份将被替换的旧版本代码。');
            $codeApplyStarted = true;
            $ledger           = $this->packages->apply($stage, $manifest, $codeBackup);

            $this->phase($state, 'code_verify', 64, '正在二次校验已写入生产目录的全部文件。');
            $this->packages->verifyInstalled($manifest);

            $this->phase($state, 'migrate', 72, '正在升级主站、插件和全部租户数据库。');
            $migrationsStarted = true;
            $this->runUpgradeCommands();

            $this->phase($state, 'verify_runtime', 88, '正在执行生产健康检查并重建缓存。');
            $this->runVerificationCommands();

            if ($maintenance) {
                $this->runArtisanProcess(['up'], '升级成功但无法退出维护模式');
                $maintenance = false;
            }

            $state['status']   = 'completed';
            $state['phase']    = 'completed';
            $state['progress'] = 100;
            $state['message']  = "LONGOS 已从 {$state['from_version']} 安全升级到 {$state['to_version']}。";
            $state['backup']   = $backupRoot;
            $this->appendLog($state, '更新完成，.env、storage、商品图片、租户文件和证书配置均未改动。');
            unset($state['release']['asset']['api_url']);
            $this->states->put($state);
            $this->cleanupWorkingDirectory($root);
            $this->pruneBackups($jobId);
        } catch (Throwable $exception) {
            $failedPhase = (string) ($state['phase'] ?? '');
            $error       = SensitiveValueRedactor::redact($exception->getMessage());
            $this->appendLog($state, '更新中断：' . $error);
            $rollbackError = null;

            if ($codeApplyStarted && ! is_array($ledger)) {
                $ledger = $this->packages->readLedger($codeBackup);
                if (! is_array($ledger)) {
                    $rollbackError = '代码回滚清单不可用；为避免带着未知代码上线，站点已保持维护模式。';
                    $this->appendLog($state, $rollbackError);
                }
            }
            if (is_array($ledger)) {
                try {
                    $this->packages->rollback($codeBackup, $ledger);
                    $this->appendLog($state, '旧版本代码已恢复。');
                } catch (Throwable $rollbackException) {
                    $rollbackError = '代码回滚失败：' . SensitiveValueRedactor::redact($rollbackException->getMessage());
                    $this->appendLog($state, $rollbackError);
                }
            }

            if ($migrationsStarted && $databasePlan !== []) {
                try {
                    $this->databases->restore($databasePath, $databasePlan);
                    $this->appendLog($state, '数据库已恢复到更新前快照。');
                } catch (Throwable $restoreException) {
                    $rollbackError = '数据库恢复失败：' . SensitiveValueRedactor::redact($restoreException->getMessage());
                    $this->appendLog($state, $rollbackError);
                }
            }

            if ($rollbackError === null && $maintenance) {
                try {
                    $this->runArtisanProcess(['optimize:clear'], '回滚后无法清理缓存');
                    $this->runArtisanProcess(['up'], '回滚成功但无法退出维护模式');
                    $maintenance = false;
                } catch (Throwable $upException) {
                    $rollbackError = SensitiveValueRedactor::redact($upException->getMessage());
                    $this->appendLog($state, $rollbackError);
                }
            }

            $state['status']   = $rollbackError === null ? 'rolled_back' : 'failed';
            $state['phase']    = $state['status'];
            $state['progress'] = $rollbackError === null ? 100 : (int) ($state['progress'] ?? 0);
            $state['message']  = $rollbackError === null
                ? '更新未生效，系统已自动恢复更新前的代码和数据库。原因：' . $error
                : '更新失败且自动恢复未完整完成，站点已保持维护模式：' . $rollbackError;
            $state['backup']            = $backupRoot;
            $state['recovery_required'] = $rollbackError !== null;
            if ($rollbackError !== null) {
                $state['recovery_phase'] = $failedPhase;
            } else {
                unset($state['recovery_phase']);
            }
            unset($state['release']['asset']['api_url']);
            $this->states->put($state);
            $this->cleanupWorkingDirectory($root);

            if ($rollbackError !== null) {
                throw new RuntimeException($state['message'], 0, $exception);
            }
        }
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $manifest */
    private function assertVersionTransition(array $state, array $manifest): void
    {
        $current = (string) ($state['from_version'] ?? config('beike.version', '0.0.0'));
        $target  = (string) ($manifest['version'] ?? '');
        if (! hash_equals((string) ($state['to_version'] ?? ''), $target) || ! version_compare($target, $current, '>')) {
            throw new RuntimeException('更新版本必须高于当前版本。');
        }
    }

    /** @param array<string, mixed> $manifest */
    private function assertWritable(array $manifest): void
    {
        if (! is_writable(storage_path('app'))) {
            throw new RuntimeException('storage/app 不可写，尚未修改任何文件。');
        }

        // Immutable container images intentionally keep the project root
        // root-owned and non-writable. Check each manifest destination (or
        // its closest existing parent) instead of weakening that boundary.
        foreach (array_keys((array) $manifest['files']) as $path) {
            $destination = base_path(str_replace('/', DIRECTORY_SEPARATOR, $path));
            $parent      = dirname($destination);
            if (is_dir($parent) && (is_writable($parent) || is_file($destination) && is_writable($destination))) {
                continue;
            }

            $probe = $parent;
            while (! file_exists($probe) && dirname($probe) !== $probe) {
                $probe = dirname($probe);
            }
            if (! is_writable($probe)) {
                throw new RuntimeException("更新目标不可写：{$path}。Coolify 使用不可变镜像，请从 GitHub/Coolify 发布新版本，不要在运行容器内覆盖代码。");
            }
        }

        foreach ((array) ($manifest['delete'] ?? []) as $path) {
            $destination = base_path(str_replace('/', DIRECTORY_SEPARATOR, (string) $path));
            if (is_file($destination) && ! is_writable(dirname($destination))) {
                throw new RuntimeException("旧版文件所在目录不可写：{$path}。尚未修改任何文件。");
            }
        }
    }

    /** @param array<string, mixed> $manifest */
    private function assertDiskSpace(string $archive, array $manifest): void
    {
        $payload = array_sum(array_map(
            static fn (string $path): int => is_file($path) ? (int) filesize($path) : 0,
            array_map(static fn (string $path): string => base_path(str_replace('/', DIRECTORY_SEPARATOR, $path)), array_keys((array) $manifest['files']))
        ));
        $required = max(268435456, ((int) filesize($archive) * 3) + $payload);
        $free     = @disk_free_space(storage_path('app'));
        if ($free !== false && (float) $free < $required) {
            throw new RuntimeException('服务器剩余磁盘空间不足以同时保存更新包和回滚备份。');
        }
    }

    private function runUpgradeCommands(): void
    {
        if (config('saas.enabled')) {
            $this->runArtisanProcess(['saas:upgrade', '--no-maintenance', '--if-needed'], 'SaaS 数据库升级失败');
        } else {
            $this->runArtisanProcess(['migrate', '--force', '--no-interaction'], '数据库升级失败');
            $this->runArtisanProcess(['optimize:clear'], '无法清理旧缓存');
            $this->runArtisanProcess(['optimize'], '无法重建生产缓存');
            $this->runArtisanProcess(['queue:restart'], '无法通知队列进程重载');
        }
    }

    private function runVerificationCommands(): void
    {
        $this->runArtisanProcess(['route:list', '--json'], '路由启动检查失败');
        if (config('saas.enabled')) {
            $this->runArtisanProcess(['saas:preflight', '--strict'], 'SaaS 上线检查失败');
        }
    }

    private function drainQueueWorkers(): void
    {
        $connectionName = (string) config('queue.default', 'sync');
        $connection     = (array) config("queue.connections.{$connectionName}", []);
        $driver         = (string) ($connection['driver'] ?? '');
        if (in_array($driver, ['sync', 'null'], true)) {
            return;
        }
        if ($driver !== 'database') {
            throw new RuntimeException("后台一键更新暂不能安全排空 {$driver} 队列；请改用 LONGOS 默认 database 队列。");
        }

        $this->runArtisanProcess(['queue:restart'], '无法通知队列进程安全退出');
        $database = $connection['connection'] ?? null;
        $table    = (string) ($connection['table'] ?? 'jobs');
        if ($table === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new RuntimeException('数据库队列表名无效，已在备份前停止更新。');
        }

        $deadline = microtime(true) + min(1800, max(10, (int) config('longos.updater.queue_drain_seconds', 300)));
        do {
            try {
                $active = DB::connection($database)->table($table)->whereNotNull('reserved_at')->count();
            } catch (Throwable $exception) {
                throw new RuntimeException('无法确认队列已排空，已在备份前停止更新：'
                    . SensitiveValueRedactor::redact($exception->getMessage()), 0, $exception);
            }
            if ($active === 0) {
                return;
            }
            usleep(250000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("仍有 {$active} 个队列任务正在执行，为避免回滚丢失数据，本次更新已在备份前取消。");
    }

    private function quiesceRuntime(): void
    {
        // Prevent schedule:run from starting another event with old code while
        // the release is being swapped. Laravel also skips ordinary scheduled
        // events in maintenance mode; the interrupt closes the narrow race for
        // an already-running scheduler loop.
        $this->runArtisanProcess(['schedule:interrupt'], '无法中断旧版本定时调度循环');

        // Maintenance mode blocks new web requests, while this short grace
        // period lets PHP-FPM requests admitted just before `down` finish their
        // database writes before the consistent snapshot is created.
        $seconds = min(60, max(0, (int) config('longos.updater.request_drain_seconds', 5)));
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    /** @param array<string, mixed> $state */
    private function isStaleQueuedState(array $state): bool
    {
        $updated = strtotime((string) ($state['updated_at'] ?? $state['created_at'] ?? ''));
        if ($updated === false) {
            return false;
        }

        return $updated < time() - max(60, (int) config('longos.updater.queued_stale_seconds', 600));
    }

    /** @param array<int, string> $arguments */
    private function runArtisanProcess(array $arguments, string $failure): void
    {
        $php = $this->launcher->phpCli();
        if ($php === null) {
            throw new RuntimeException('未找到可执行的 PHP CLI，无法安全执行数据库升级。');
        }
        $process = new Process(
            array_merge([$php, base_path('artisan')], $arguments),
            base_path(),
            null,
            null,
            max(60, (int) config('longos.updater.command_timeout', 3600)),
        );
        $process->run();
        if (! $process->isSuccessful()) {
            $output = SensitiveValueRedactor::redact(trim($process->getErrorOutput() . "\n" . $process->getOutput()));

            throw new RuntimeException($failure . ($output !== '' ? '：' . mb_substr($output, 0, 3000) : '。'));
        }
    }

    /** @param array<string, mixed> $state */
    private function phase(array &$state, string $phase, int $progress, string $message): void
    {
        $state['status']   = 'running';
        $state['phase']    = $phase;
        $state['progress'] = $progress;
        $state['message']  = $message;
        $this->appendLog($state, $message);
        $this->states->put($state);
    }

    /** @param array<string, mixed> $state */
    private function appendLog(array &$state, string $message): void
    {
        $logs          = array_values((array) ($state['logs'] ?? []));
        $logs[]        = ['time' => now()->toIso8601String(), 'message' => mb_substr(SensitiveValueRedactor::redact($message), 0, 4000)];
        $state['logs'] = array_slice($logs, -100);
    }

    private function cleanupWorkingDirectory(string $directory): void
    {
        $root = realpath(storage_path('app/private/longos-updater/jobs'));
        $real = realpath($directory);
        if (! is_string($root) || ! is_string($real) || ! str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($real);
    }

    private function pruneBackups(string $currentJobId): void
    {
        $retain = max(1, (int) config('longos.updater.retain_backups', 3));
        $root   = storage_path('app/private/longos-updater/backups');
        $items  = glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        usort($items, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($items, $retain) as $item) {
            if (basename($item) === $currentJobId || ! Str::isUuid(basename($item))) {
                continue;
            }
            $this->cleanupBackupDirectory($item, $root);
        }
    }

    private function cleanupBackupDirectory(string $directory, string $root): void
    {
        $rootReal = realpath($root);
        $real     = realpath($directory);
        if (! is_string($rootReal) || ! is_string($real) || ! str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($real);
    }

    private function assertEnabled(): void
    {
        if (! config('longos.updater.enabled', false)) {
            throw new RuntimeException('Coolify 不可变镜像模式已禁用应用内文件更新，请在 Coolify Deployments 中发布或回滚。');
        }
    }
}
