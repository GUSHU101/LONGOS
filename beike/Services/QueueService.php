<?php

namespace Beike\Services;

use Beike\Installer\Helpers\EnvironmentManager;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class QueueService
{
    /** @var array<string, array{at: int, busy_until: int}> */
    private static array $heartbeatWrites = [];

    private const MAX_HEARTBEAT_LEASE_SECONDS = 86400;

    /**
     * 获取队列日志文件路径
     */
    protected static function getQueueLogPath(): string
    {
        $logPath = storage_path('logs/queue');
        if (! file_exists($logPath)) {
            mkdir($logPath, 0755, true);
        }

        return $logPath . '/queue-' . date('Y-m-d') . '.log';
    }

    /**
     * 写入日志
     */
    protected static function writeLog($message, $type = 'info'): void
    {
        $logFile    = self::getQueueLogPath();
        $dateTime   = date('Y-m-d H:i:s');
        $logMessage = "[{$dateTime}] [{$type}] {$message}" . PHP_EOL;
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }

    /**
     * 启动队列处理进程
     *
     * @param array $options 可选配置参数
     * @return bool
     */
    public static function startQueueWorker(array $options = []): bool
    {
        try {
            // 默认配置
            $defaultOptions = [
                'php_path'        => '/usr/bin/php',
                'timeout'         => null,
                'queue'           => 'default',
                'tries'           => 3,
                'memory'          => 256,
                'sleep'           => 3,
                'stop_when_empty' => true,
                'log_output'      => true,
                'output_file'     => self::getQueueLogPath(),
            ];

            // 合并配置
            $options = array_merge($defaultOptions, $options);

            $command = self::workerCommand($options);
            self::writeLog('Starting queue worker with validated arguments');

            // Execute an argument vector, never an interpolated shell line.
            $process = new Process($command, base_path(), null, null, $options['timeout']);
            $process->run($options['log_output'] ? function ($type, $buffer) {
                if ($type === Process::ERR) {
                    self::writeLog($buffer, 'error');
                } else {
                    self::writeLog($buffer);
                }
            } : null);

            // 检查进程是否成功启动
            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            // 记录启动成功
            self::writeLog('Queue worker started successfully', 'success');

            return true;
        } catch (\Exception $e) {
            self::writeLog("Failed to start queue worker: {$e->getMessage()}", 'error');
            self::writeLog("Stack trace: {$e->getTraceAsString()}", 'error');

            return false;
        }
    }

    /**
     * 检查队列工作进程是否在运行
     *
     * @return bool
     */
    public static function isQueueWorkerRunning(string $queue = 'default'): bool
    {
        if (self::hasRecentWorkerHeartbeat($queue)) {
            return true;
        }

        try {
            $process = new Process(['pgrep', '-af', 'artisan queue:work']);
            $process->run();

            $isRunning = false;
            if ($process->isSuccessful()) {
                foreach (array_filter(explode("\n", $process->getOutput())) as $processInfo) {
                    $parts = preg_split('/\s+/', trim($processInfo), 2);
                    if (! is_array($parts) || count($parts) < 2 || ! ctype_digit($parts[0])) {
                        continue;
                    }
                    $workingDirectory = @readlink('/proc/' . $parts[0] . '/cwd');
                    if (self::workerMatchesProject($parts[1], is_string($workingDirectory) ? $workingDirectory : null, $queue)) {
                        $isRunning = true;

                        break;
                    }
                }
            }
            self::writeLog(
                $isRunning ? 'Queue worker is running' : 'Queue worker is not running',
                $isRunning ? 'info' : 'warning'
            );

            return $isRunning;
        } catch (\Exception $e) {
            self::writeLog("Failed to check queue worker status: {$e->getMessage()}", 'error');

            return false;
        }
    }

    /**
     * Queue workers can run in another Docker container where /proc is not
     * shared with the web container. Record a tiny heartbeat on the shared
     * storage volume so the web process can distinguish an idle worker from
     * a missing one without pushing a sacrificial job.
     */
    public static function recordWorkerHeartbeat(string $queues, int $busyForSeconds = 0, bool $force = false): void
    {
        $now            = time();
        $busyForSeconds = max(0, min(self::MAX_HEARTBEAT_LEASE_SECONDS, $busyForSeconds));
        $busyUntil      = $busyForSeconds > 0 ? $now + $busyForSeconds : 0;
        foreach (self::queueNames($queues) as $queue) {
            $lastWrite = self::$heartbeatWrites[$queue] ?? null;
            if (! $force
                && is_array($lastWrite)
                && ($now - $lastWrite['at']) < 2
                && $lastWrite['busy_until'] === $busyUntil
            ) {
                continue;
            }
            self::$heartbeatWrites[$queue] = ['at' => $now, 'busy_until' => $busyUntil];
            $path                          = self::workerHeartbeatPath($queue, self::workerIdentity());
            $directory                     = self::workerHeartbeatDirectory();
            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                continue;
            }
            $payload = json_encode([
                'seen_at'    => $now,
                'busy_until' => $busyUntil,
            ], JSON_UNESCAPED_SLASHES);
            if (is_string($payload)) {
                @file_put_contents($path, $payload, LOCK_EX);
            }
        }
    }

    public static function hasRecentWorkerHeartbeat(string $queue, int $maximumAgeSeconds = 15): bool
    {
        if (! preg_match('/^[A-Za-z0-9_.:-]+$/', $queue)) {
            return false;
        }

        $now   = time();
        $paths = glob(self::workerHeartbeatPath($queue, '*')) ?: [];
        // Read the single-file format written by LONGOS 1.6.5 during a rolling
        // deployment. New versions keep one file per worker so an idle worker
        // can never clear another worker's active job lease.
        $paths[] = self::legacyWorkerHeartbeatPath($queue);

        foreach (array_values(array_unique($paths)) as $path) {
            $payload = @file_get_contents($path);
            if (! is_string($payload)) {
                continue;
            }
            $heartbeat = self::parseWorkerHeartbeat($payload);
            if ($heartbeat === null) {
                continue;
            }
            ['seen_at' => $seenAt, 'busy_until' => $busyUntil] = $heartbeat;
            $validClock                                        = $seenAt                         <= ($now + 60);
            $recent                                            = $validClock && ($now - $seenAt) <= max(3, $maximumAgeSeconds);
            $busy                                              = $validClock
                && $busyUntil >= $now
                && $busyUntil <= ($seenAt + self::MAX_HEARTBEAT_LEASE_SECONDS);
            if ($recent || $busy) {
                return true;
            }

            if ($seenAt < ($now - (self::MAX_HEARTBEAT_LEASE_SECONDS * 2))) {
                @unlink($path);
            }
        }

        return false;
    }

    /**
     * 停止所有队列工作进程
     *
     * @return bool
     */
    public static function stopQueueWorkers(): bool
    {
        try {
            self::writeLog('Requesting a graceful queue worker restart');

            // This application's cache-scoped signal cannot kill queue
            // workers belonging to another project on the same server.
            $process = new Process([PHP_BINARY, base_path('artisan'), 'queue:restart'], base_path());
            $process->run();

            if ($process->isSuccessful()) {
                self::writeLog('Queue workers received the graceful restart signal', 'success');

                return true;
            }
            self::writeLog('Queue restart signal failed: ' . trim($process->getErrorOutput()), 'warning');

            return false;

        } catch (\Exception $e) {
            self::writeLog("Failed to stop queue workers: {$e->getMessage()}", 'error');

            return false;
        }
    }

    /**
     * 重启队列工作进程
     *
     * @param array $options
     * @return bool
     */
    public static function restartQueueWorker(array $options = []): bool
    {
        self::writeLog('Attempting to restart queue worker');

        self::stopQueueWorkers();
        sleep(2); // 等待进程完全停止

        $result = self::startQueueWorker($options);

        if ($result) {
            self::writeLog('Queue worker restarted successfully', 'success');
        } else {
            self::writeLog('Failed to restart queue worker', 'error');
        }

        return $result;
    }

    /**
     * 获取队列状态信息
     *
     * @return array
     */
    public static function getQueueStatus(): array
    {
        try {
            $process = new Process(['pgrep', '-af', 'artisan queue:work']);
            $process->run();

            $output    = $process->getOutput();
            $processes = array_filter(explode("\n", $output));

            $status = [
                'running'       => ! empty($processes),
                'process_count' => count($processes),
                'processes'     => [],
            ];

            foreach ($processes as $processInfo) {
                $parts = preg_split('/\s+/', trim($processInfo), 2);
                if (! is_array($parts) || count($parts) < 2 || ! ctype_digit($parts[0])) {
                    continue;
                }
                $status['processes'][] = [
                    'pid'     => (int) $parts[0],
                    'command' => mb_substr($parts[1], 0, 500),
                ];
            }
            $status['process_count'] = count($status['processes']);
            $status['running']       = $status['process_count'] > 0;

            self::writeLog('Queue status checked: ' . json_encode($status));

            return $status;
        } catch (\Exception $e) {
            self::writeLog("Failed to get queue status: {$e->getMessage()}", 'error');

            return ['running' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 更新 .env 文件中的值
     *
     * @param array $values
     * @return bool
     */
    public static function updateEnv(array $values): bool
    {
        if (! is_file(base_path('.env'))) {
            return false;
        }

        // Reuse the installer's key validation, escaping, file lock and
        // atomic replacement so an interrupted write cannot truncate .env.
        return (new EnvironmentManager)->setValues($values);
    }

    /** @return list<string> */
    private static function workerCommand(array $options): array
    {
        $phpPath = trim((string) ($options['php_path'] ?? ''));
        $queue   = trim((string) ($options['queue'] ?? 'default'));
        if ($phpPath === '' || str_contains($phpPath, "\0")) {
            throw new \InvalidArgumentException('The PHP executable path is invalid.');
        }
        if ($queue === '' || ! preg_match('/^[A-Za-z0-9_,:-]+$/', $queue)) {
            throw new \InvalidArgumentException('The queue name is invalid.');
        }

        $tries  = self::boundedInteger($options['tries'] ?? 3, 1, 100, 'tries');
        $memory = self::boundedInteger($options['memory'] ?? 256, 32, 8192, 'memory');
        $sleep  = self::boundedInteger($options['sleep'] ?? 3, 0, 300, 'sleep');

        $command = [
            $phpPath,
            base_path('artisan'),
            'queue:work',
            "--queue={$queue}",
            "--tries={$tries}",
            "--memory={$memory}",
            "--sleep={$sleep}",
        ];
        if ((bool) ($options['stop_when_empty'] ?? true)) {
            $command[] = '--stop-when-empty';
        }

        return $command;
    }

    /**
     * A shared container host may run queue workers for several unrelated
     * sites. Match both the project and queue before treating a worker as
     * available; otherwise this application can enqueue work that nobody
     * will ever consume.
     */
    private static function workerMatchesProject(string $command, ?string $workingDirectory, string $queue): bool
    {
        $command = str_replace('\\', '/', trim($command));
        if ($command === '' || ! str_contains($command, 'artisan') || ! str_contains($command, 'queue:work') || str_contains($command, 'pgrep')) {
            return false;
        }

        if (preg_match('/--queue(?:=|\s+)([^\s]+)/', $command, $matches)) {
            $queues = array_filter(array_map(
                static fn (string $value): string => trim($value, " \t\n\r\0\x0B\"'"),
                explode(',', $matches[1]),
            ));
            if (! in_array($queue, $queues, true)) {
                return false;
            }
        } elseif ($queue !== 'default') {
            return false;
        }

        $root    = str_replace('\\', '/', (string) (realpath(base_path()) ?: base_path()));
        $artisan = $root . '/artisan';
        if (str_contains($command, $artisan)) {
            return true;
        }

        if (! is_string($workingDirectory) || $workingDirectory === '') {
            return false;
        }
        $workingDirectory = str_replace('\\', '/', (string) (realpath($workingDirectory) ?: $workingDirectory));

        return rtrim($workingDirectory, '/')                              === rtrim($root, '/')
            && preg_match('/(?:^|\s)(?:\.\/)?artisan(?:\s|$)/', $command) === 1;
    }

    /** @return list<string> */
    private static function queueNames(string $queues): array
    {
        return array_values(array_filter(array_map(
            static fn (string $queue): string => trim($queue),
            explode(',', $queues),
        ), static fn (string $queue): bool => preg_match('/^[A-Za-z0-9_.:-]+$/', $queue) === 1));
    }

    private static function workerHeartbeatDirectory(): string
    {
        return storage_path('framework/queue-heartbeats');
    }

    private static function workerHeartbeatPath(string $queue, string $worker): string
    {
        return self::workerHeartbeatDirectory() . '/' . hash('sha256', $queue) . '-' . $worker . '.heartbeat';
    }

    private static function legacyWorkerHeartbeatPath(string $queue): string
    {
        return self::workerHeartbeatDirectory() . '/' . hash('sha256', $queue) . '.heartbeat';
    }

    private static function workerIdentity(): string
    {
        $host = function_exists('gethostname') ? (string) gethostname() : php_uname('n');

        return hash('sha256', $host . ':' . getmypid());
    }

    /** @return array{seen_at: int, busy_until: int}|null */
    private static function parseWorkerHeartbeat(string $payload): ?array
    {
        $payload = trim($payload);
        if (ctype_digit($payload)) {
            return ['seen_at' => (int) $payload, 'busy_until' => 0];
        }

        $decoded = json_decode($payload, true);
        if (! is_array($decoded)
            || filter_var($decoded['seen_at'] ?? null, FILTER_VALIDATE_INT)    === false
            || filter_var($decoded['busy_until'] ?? null, FILTER_VALIDATE_INT) === false
        ) {
            return null;
        }

        return [
            'seen_at'    => (int) $decoded['seen_at'],
            'busy_until' => (int) $decoded['busy_until'],
        ];
    }

    private static function boundedInteger(mixed $value, int $minimum, int $maximum, string $name): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException("The {$name} option must be an integer.");
        }

        $value = (int) $value;
        if ($value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException("The {$name} option is outside the allowed range.");
        }

        return $value;
    }
}
