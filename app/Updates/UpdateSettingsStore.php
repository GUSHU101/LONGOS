<?php

namespace App\Updates;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use RuntimeException;

class UpdateSettingsStore
{
    private const FILE = 'private/longos-updater/settings.json';

    /** @return array{repository:string,channel:string,token:string,has_token:bool} */
    public function get(): array
    {
        $stored = $this->read();
        $token  = '';

        if (is_string($stored['token'] ?? null) && $stored['token'] !== '') {
            try {
                $token = Crypt::decryptString($stored['token']);
            } catch (DecryptException) {
                $token = '';
            }
        }

        $repository = trim((string) ($stored['repository'] ?? config('longos.updater.repository', '')));
        $channel    = strtolower(trim((string) ($stored['channel'] ?? config('longos.updater.channel', 'stable'))));

        return [
            'repository' => $repository,
            'channel'    => in_array($channel, ['stable', 'prerelease'], true) ? $channel : 'stable',
            'token'      => $token,
            'has_token'  => $token !== '',
        ];
    }

    /** @return array{repository:string,channel:string,token:string,has_token:bool} */
    public function save(string $repository, string $channel, ?string $token, bool $clearToken = false): array
    {
        $repository = trim($repository);
        $channel    = strtolower(trim($channel));

        $segments = explode('/', $repository);
        if (count($segments) !== 2
            || ! preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/', $segments[0])
            || ! preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $segments[1])
            || in_array($segments[1], ['.', '..'], true)) {
            throw new InvalidArgumentException('GitHub 仓库必须使用 owner/repository 格式。');
        }
        if (! in_array($channel, ['stable', 'prerelease'], true)) {
            throw new InvalidArgumentException('更新通道无效。');
        }

        $current = $this->read();
        if ($clearToken) {
            $encryptedToken = '';
        } elseif ($token !== null && trim($token) !== '') {
            $token = trim($token);
            if (strlen($token) > 512 || preg_match('/[\x00-\x1F\x7F]/', $token)) {
                throw new InvalidArgumentException('GitHub 令牌格式无效。');
            }
            $encryptedToken = Crypt::encryptString($token);
        } else {
            $encryptedToken = (string) ($current['token'] ?? '');
        }

        $this->write([
            'schema'     => 1,
            'repository' => $repository,
            'channel'    => $channel,
            'token'      => $encryptedToken,
            'updated_at' => now()->toIso8601String(),
        ]);

        return $this->get();
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $path = storage_path('app/' . self::FILE);
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    private function write(array $payload): void
    {
        $path      = storage_path('app/' . self::FILE);
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('无法创建更新设置目录。');
        }

        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        $json      = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->writeDurably($temporary, $json . PHP_EOL);
        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException('无法原子替换更新设置。');
        }
        @chmod($path, 0600);
    }

    private function writeDurably(string $path, string $contents): void
    {
        $handle = @fopen($path, 'xb');
        if (! is_resource($handle)) {
            throw new RuntimeException('无法保存更新设置。');
        }

        try {
            $length = strlen($contents);
            $offset = 0;
            while ($offset < $length) {
                $written = @fwrite($handle, substr($contents, $offset));
                if (! is_int($written) || $written < 1) {
                    throw new RuntimeException('无法保存更新设置。');
                }
                $offset += $written;
            }
            if (! @fflush($handle) || (function_exists('fsync') && ! @fsync($handle))) {
                throw new RuntimeException('无法将更新设置落盘。');
            }
        } catch (\Throwable $exception) {
            @fclose($handle);
            @unlink($path);

            throw $exception;
        }
        @fclose($handle);
        @chmod($path, 0600);
    }
}
