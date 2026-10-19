<?php

namespace Beike\Libraries;

class ToolCache
{
    protected static function basePath()
    {
        return storage_path('app/tool_cache');
    }

    protected static function filePath($key)
    {
        $safeKey = hash('sha256', (string) $key);

        return self::basePath() . '/' . $safeKey . '.json';
    }

    /**
     * 获取缓存
     */
    public static function get($key, $default = null)
    {
        $file = self::filePath($key);

        if (! file_exists($file)) {
            return $default;
        }

        $data = self::readPayload($file);

        if (! is_array($data)) {
            return $default;
        }

        // 检查过期
        if (! empty($data['expire']) && time() > $data['expire']) {
            self::forgetIfExpired($key);

            return $default;
        }

        return $data['value'] ?? $default;
    }

    /**
     * 判断缓存是否存在
     */
    public static function has($key): bool
    {
        $file = self::filePath($key);

        if (! file_exists($file)) {
            return false;
        }

        $data = self::readPayload($file);

        if (! is_array($data)) {
            return false;
        }

        // 检查过期
        if (! empty($data['expire']) && time() > $data['expire']) {
            self::forgetIfExpired($key);

            return false;
        }

        return array_key_exists('value', $data);
    }

    /**
     * 写入缓存
     */
    public static function put($key, $value, $ttl = null)
    {
        if (! self::prepareDirectory()) {
            return false;
        }

        $file = self::filePath($key);

        $expire = null;

        // 兼容 Laravel 的时间对象: now()->addDays()
        if ($ttl instanceof \DateTimeInterface) {
            $expire = $ttl->getTimestamp();
        } elseif (is_numeric($ttl)) {
            $expire = time() + $ttl;
        }

        $data = [
            'value'  => $value,
            'expire' => $expire,
        ];

        // 防止并发写入：文件锁
        if (is_link($file)) {
            return false;
        }

        $fp = fopen($file, 'c+b');
        if ($fp === false) {
            return false;
        }

        try {
            if (! flock($fp, LOCK_EX)) {
                return false;
            }
            $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            rewind($fp);
            if (! ftruncate($fp, 0) || fwrite($fp, $payload) !== strlen($payload) || ! fflush($fp)) {
                return false;
            }
            @chmod($file, 0600);
        } catch (\Throwable) {
            return false;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        return true;
    }

    /**
     * 仅在缓存不存在时写入
     */
    public static function add($key, $value, $ttl = null): bool
    {
        if (! self::prepareDirectory()) {
            return false;
        }

        $file = self::filePath($key);
        if (is_link($file)) {
            return false;
        }

        $fp = fopen($file, 'c+b');
        if ($fp === false) {
            return false;
        }

        if (! flock($fp, LOCK_EX)) {
            fclose($fp);

            return false;
        }

        $contents = stream_get_contents($fp);
        $data     = $contents ? json_decode($contents, true) : null;

        if (is_array($data)) {
            $notExpired = empty($data['expire']) || time() <= $data['expire'];
            if ($notExpired) {
                flock($fp, LOCK_UN);
                fclose($fp);

                return false;
            }
        }

        $expire = null;
        if ($ttl instanceof \DateTimeInterface) {
            $expire = $ttl->getTimestamp();
        } elseif (is_numeric($ttl)) {
            $expire = time() + $ttl;
        }

        $payload = [
            'value'  => $value,
            'expire' => $expire,
        ];

        try {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            rewind($fp);
            if (! ftruncate($fp, 0) || fwrite($fp, $encoded) !== strlen($encoded) || ! fflush($fp)) {
                return false;
            }
            @chmod($file, 0600);
        } catch (\Throwable) {
            return false;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        return true;
    }

    /**
     * 删除缓存
     */
    public static function forget($key)
    {
        $file = self::filePath($key);
        if (! file_exists($file)) {
            return true;
        }

        return self::clearFileWithLock($file);
    }

    /**
     * 仅当当前缓存值属于调用者时删除。
     *
     * 用于并发锁释放，避免超时的旧请求误删新请求刚获取的锁。
     */
    public static function forgetIfValue($key, mixed $expectedValue): bool
    {
        $file = self::filePath($key);
        if (! file_exists($file) || is_link($file)) {
            return false;
        }

        $handle = @fopen($file, 'c+b');
        if ($handle === false) {
            return false;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return false;
            }

            rewind($handle);
            $contents = stream_get_contents($handle);
            $data     = is_string($contents) && $contents !== '' ? json_decode($contents, true) : null;
            if (! is_array($data) || ! array_key_exists('value', $data) || $data['value'] !== $expectedValue) {
                return false;
            }

            return self::truncateLockedFile($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function prepareDirectory(): bool
    {
        $directory = self::basePath();
        if (is_link($directory)) {
            return false;
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return false;
        }

        @chmod($directory, 0700);

        return is_writable($directory);
    }

    private static function readPayload(string $file): ?array
    {
        if (is_link($file)) {
            return null;
        }

        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                return null;
            }
            $contents = stream_get_contents($handle);

            return is_string($contents) ? json_decode($contents, true) : null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function forgetIfExpired($key): bool
    {
        $file = self::filePath($key);
        if (! file_exists($file) || is_link($file)) {
            return false;
        }

        $handle = @fopen($file, 'c+b');
        if ($handle === false) {
            return false;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return false;
            }

            rewind($handle);
            $contents = stream_get_contents($handle);
            $data     = is_string($contents) && $contents !== '' ? json_decode($contents, true) : null;
            if (! is_array($data) || empty($data['expire']) || time() <= $data['expire']) {
                return false;
            }

            return self::truncateLockedFile($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function clearFileWithLock(string $file): bool
    {
        if (is_link($file)) {
            return false;
        }

        $handle = @fopen($file, 'c+b');
        if ($handle === false) {
            return false;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return false;
            }

            return self::truncateLockedFile($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 保留空文件而不直接 unlink，可确保等待同一 inode 锁的写入者不会被后续删除。
     *
     * @param resource $handle
     */
    private static function truncateLockedFile($handle): bool
    {
        rewind($handle);

        return ftruncate($handle, 0) && fflush($handle);
    }
}
