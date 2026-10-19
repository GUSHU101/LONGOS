<?php

namespace App\Updates;

use RuntimeException;

class UpdateStateStore
{
    private const DIRECTORY = 'app/private/longos-updater';

    /** @return array<string, mixed> */
    public function get(): array
    {
        $path = storage_path(self::DIRECTORY . '/state.json');
        if (! is_file($path)) {
            return $this->idle();
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? array_merge($this->idle(), $decoded) : $this->idle();
    }

    /** @param array<string, mixed> $state */
    public function put(array $state): void
    {
        $directory = storage_path(self::DIRECTORY);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('无法创建更新状态目录。');
        }

        $state['updated_at'] = now()->toIso8601String();
        $path                = $directory . '/state.json';
        $temporary           = $path . '.tmp-' . bin2hex(random_bytes(8));
        $json                = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->writeDurably($temporary, $json . PHP_EOL, '无法保存更新状态。');
        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException('无法原子替换更新状态。');
        }
        @chmod($path, 0600);
    }

    private function writeDurably(string $path, string $contents, string $error): void
    {
        $handle = @fopen($path, 'xb');
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
            @fclose($handle);
            @unlink($path);

            throw $exception;
        }
        @fclose($handle);
        @chmod($path, 0600);
    }

    /** @return resource */
    public function acquireLock()
    {
        $directory = storage_path(self::DIRECTORY);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('无法创建更新锁目录。');
        }

        $handle = @fopen($directory . '/update.lock', 'c+');
        if (! is_resource($handle) || ! @flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new RuntimeException('已有 LONGOS 更新正在运行。');
        }
        @chmod($directory . '/update.lock', 0600);

        return $handle;
    }

    /** @param resource $handle */
    public function releaseLock($handle): void
    {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    /** Check the operating-system lock without trusting a stale JSON state. */
    public function workerActive(): bool
    {
        $directory = storage_path(self::DIRECTORY);
        if (! is_dir($directory)) {
            return false;
        }
        $handle = @fopen($directory . '/update.lock', 'c+');
        if (! is_resource($handle)) {
            // Fail closed: inability to inspect the lock must not make a live
            // updater look recoverable to another process.
            return true;
        }
        $acquired = @flock($handle, LOCK_EX | LOCK_NB);
        if ($acquired) {
            @flock($handle, LOCK_UN);
        }
        @fclose($handle);

        return ! $acquired;
    }

    /** @return array<string, mixed> */
    private function idle(): array
    {
        return [
            'id'                => null,
            'status'            => 'idle',
            'phase'             => 'idle',
            'progress'          => 0,
            'message'           => '尚未执行更新。',
            'from_version'      => (string) config('beike.version', ''),
            'to_version'        => null,
            'recovery_required' => false,
            'logs'              => [],
            'updated_at'        => null,
        ];
    }
}
