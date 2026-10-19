<?php

namespace App\Updates;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

class ReleasePackage
{
    private const MANIFEST = 'longos-release.json';

    private const PROTECTED = [
        '.env',
        '.git',
        '.github',
        'storage',
        'bootstrap/cache',
        'node_modules',
        'public/storage',
        'public/cache',
        'public/image/catalog',
        'public/plugin',
        'public/upload',
        // Deployment controls belong to Git and Coolify. A legacy web updater
        // must never alter the immutable image or proxy definition.
        'deploy',
        'docker',
        'scripts',
        'install.sh',
        'docker-compose.yml',
    ];

    /**
     * Extract and verify a release without ever trusting archive paths.
     *
     * @return array{root:string,manifest:array<string,mixed>}
     */
    public function stage(string $archive, string $directory, string $expectedVersion): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('服务器未启用 PHP ZipArchive，无法安全解压更新包。');
        }
        if (! is_file($archive)) {
            throw new RuntimeException('更新包不存在。');
        }

        $zip = new ZipArchive;
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('更新包不是有效的 ZIP 文件。');
        }

        try {
            $maximumFiles = max(100, (int) config('longos.updater.maximum_files', 100000));
            if ($zip->numFiles < 1 || $zip->numFiles > $maximumFiles) {
                throw new RuntimeException('更新包文件数量异常。');
            }

            $entries                = [];
            $caseInsensitiveEntries = [];
            $total                  = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat) || ! is_string($stat['name'] ?? null)) {
                    throw new RuntimeException('更新包目录结构损坏。');
                }
                $path = $this->normalizePath($stat['name']);
                if ($path === '') {
                    continue;
                }
                if (isset($entries[$path])) {
                    throw new RuntimeException("更新包包含重复路径：{$path}");
                }
                $caseKey = strtolower($path);
                if (isset($caseInsensitiveEntries[$caseKey])) {
                    throw new RuntimeException("更新包包含大小写冲突路径：{$path}");
                }
                $caseInsensitiveEntries[$caseKey] = true;
                if ($this->isSymlink($zip, $index)) {
                    throw new RuntimeException("更新包不能包含符号链接：{$path}");
                }

                $isDirectory = str_ends_with(str_replace('\\', '/', $stat['name']), '/');
                $size        = (int) ($stat['size'] ?? 0);
                if ($size < 0) {
                    throw new RuntimeException("更新包文件大小无效：{$path}");
                }
                $total += $size;
                if ($total > max(1, (int) config('longos.updater.maximum_bytes', 1073741824)) * 3) {
                    throw new RuntimeException('更新包解压后体积超过安全上限。');
                }
                $entries[$path] = ['index' => $index, 'directory' => $isDirectory, 'size' => $size];
            }

            if (! isset($entries[self::MANIFEST]) || $entries[self::MANIFEST]['directory']) {
                throw new RuntimeException('更新包缺少 longos-release.json。');
            }
            if ($entries[self::MANIFEST]['size'] < 2 || $entries[self::MANIFEST]['size'] > 16777216) {
                throw new RuntimeException('更新包发布清单大小异常。');
            }

            $diskPath = dirname($directory);
            while (! is_dir($diskPath) && dirname($diskPath) !== $diskPath) {
                $diskPath = dirname($diskPath);
            }
            $free     = @disk_free_space($diskPath);
            $required = max(268435456, $total * 2 + (int) filesize($archive));
            if ($free !== false && (float) $free < $required) {
                throw new RuntimeException('服务器剩余磁盘空间不足以安全解压和校验更新包。');
            }

            $manifestText = $zip->getFromIndex($entries[self::MANIFEST]['index']);
            $manifest     = json_decode(is_string($manifestText) ? $manifestText : '', true);
            $this->validateManifest($manifest, $expectedVersion, $entries);

            $this->removeDirectory($directory);
            if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('无法创建更新暂存目录。');
            }

            foreach ($entries as $path => $entry) {
                if ($path === self::MANIFEST || $entry['directory']) {
                    continue;
                }
                $target = $directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
                $parent = dirname($target);
                if (! is_dir($parent) && ! @mkdir($parent, 0755, true) && ! is_dir($parent)) {
                    throw new RuntimeException("无法创建暂存目录：{$path}");
                }

                $input  = $zip->getStream($zip->getNameIndex($entry['index']));
                $output = @fopen($target, 'xb');
                if (! is_resource($input) || ! is_resource($output)) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }

                    throw new RuntimeException("无法解压更新文件：{$path}");
                }
                $written = stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                if ($written !== $entry['size']) {
                    throw new RuntimeException("更新文件解压不完整：{$path}");
                }
                @chmod($target, $this->modeFor($path));
            }

            foreach ((array) $manifest['files'] as $path => $sha256) {
                $target = $directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
                $actual = hash_file('sha256', $target);
                if (! is_string($actual) || ! hash_equals((string) $sha256, strtolower($actual))) {
                    throw new RuntimeException("发布清单校验失败：{$path}");
                }
            }
            @file_put_contents(
                $directory . DIRECTORY_SEPARATOR . self::MANIFEST,
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL,
                LOCK_EX,
            );

            return ['root' => $directory, 'manifest' => $manifest];
        } finally {
            $zip->close();
        }
    }

    /**
     * Replace only manifest-owned files and record a complete rollback ledger.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function apply(string $stage, array $manifest, string $backup): array
    {
        if (! is_dir($backup) && ! @mkdir($backup, 0700, true) && ! is_dir($backup)) {
            throw new RuntimeException('无法创建代码备份目录。');
        }

        $ledger = [
            'schema'   => 2,
            'replaced' => [],
            'created'  => [],
            'deleted'  => [],
            'backups'  => [],
            'modes'    => [],
        ];
        $this->writeLedger($backup, $ledger);
        foreach (array_keys((array) $manifest['files']) as $path) {
            $source      = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $destination = base_path(str_replace('/', DIRECTORY_SEPARATOR, $path));
            $this->backupExisting($path, $destination, $backup, $ledger);
            // Persist the recovery instruction before changing the live file.
            // A disk-full, timeout or killed PHP-FPM process can therefore be
            // recovered by the manager even when apply() never returns.
            $this->writeLedger($backup, $ledger);
            $this->atomicCopy($source, $destination, $path);
        }

        foreach ((array) ($manifest['delete'] ?? []) as $path) {
            $destination = base_path(str_replace('/', DIRECTORY_SEPARATOR, $path));
            if (! is_file($destination)) {
                continue;
            }
            $this->backupExisting($path, $destination, $backup, $ledger, 'deleted');
            $this->writeLedger($backup, $ledger);
            if (! @unlink($destination)) {
                throw new RuntimeException("无法移除旧版本文件：{$path}");
            }
            $this->fsyncParent($destination);
        }

        $this->writeLedger($backup, $ledger);

        return $ledger;
    }

    /** @param array<string, mixed> $manifest */
    public function verifyInstalled(array $manifest): void
    {
        foreach ((array) ($manifest['files'] ?? []) as $path => $expectedHash) {
            $path        = (string) $path;
            $destination = base_path(str_replace('/', DIRECTORY_SEPARATOR, $path));
            if ($this->normalizePath($path) !== $path || $this->isProtected($path)
                                                      || ! is_file($destination) || is_link($destination)) {
                throw new RuntimeException("生产文件写入后完整性校验失败：{$path}");
            }
            $actualHash = hash_file('sha256', $destination);
            if (! is_string($actualHash) || ! hash_equals((string) $expectedHash, strtolower($actualHash))) {
                throw new RuntimeException("生产文件写入后哈希不一致：{$path}");
            }
        }
    }

    /** @return array<string, mixed>|null */
    public function readLedger(string $backup): ?array
    {
        $path = $backup . DIRECTORY_SEPARATOR . 'ledger.json';
        if (! is_file($path)) {
            return null;
        }

        $ledger = json_decode((string) @file_get_contents($path), true);
        if (! is_array($ledger)
            || ! is_array($ledger['replaced'] ?? null)
            || ! is_array($ledger['created'] ?? null)
            || ! is_array($ledger['deleted'] ?? null)) {
            return null;
        }

        try {
            $this->validateLedger($ledger);

            return $ledger;
        } catch (RuntimeException) {
            return null;
        }
    }

    /** @param array<string, mixed> $ledger */
    public function rollback(string $backup, array $ledger): void
    {
        $this->validateLedger($ledger);
        foreach (array_unique(array_merge((array) $ledger['replaced'], (array) $ledger['deleted'])) as $path) {
            $source      = $backup . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $destination = base_path(str_replace('/', DIRECTORY_SEPARATOR, $path));
            if (! is_file($source)) {
                throw new RuntimeException("代码回滚备份缺失：{$path}");
            }
            $expectedHash = (string) (($ledger['backups'] ?? [])[$path] ?? '');
            if ($expectedHash !== '') {
                $actualHash = hash_file('sha256', $source);
                if (! is_string($actualHash) || ! hash_equals($expectedHash, $actualHash)) {
                    throw new RuntimeException("代码回滚备份完整性校验失败：{$path}");
                }
            }
            $mode = isset($ledger['modes'][$path]) ? (int) $ledger['modes'][$path] : null;
            $this->atomicCopy($source, $destination, $path, $mode);
        }

        foreach ((array) $ledger['created'] as $path) {
            $destination = base_path(str_replace('/', DIRECTORY_SEPARATOR, $path));
            if (is_file($destination) && ! @unlink($destination)) {
                throw new RuntimeException("无法移除更新时新建的文件：{$path}");
            }
            $this->fsyncParent($destination);
        }
    }

    /** @return array<int, string> */
    public static function protectedPaths(): array
    {
        return self::PROTECTED;
    }

    /** @param mixed $manifest @param array<string, array{index:int,directory:bool,size:int}> $entries */
    private function validateManifest(mixed $manifest, string $expectedVersion, array $entries): void
    {
        if (! is_array($manifest)
            || (int) ($manifest['schema'] ?? 0) !== 1
            || ($manifest['product'] ?? null)   !== 'LONGOS'
            || ! is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('LONGOS 发布清单格式无效。');
        }
        if (! hash_equals($expectedVersion, (string) ($manifest['version'] ?? ''))) {
            throw new RuntimeException('发布包版本与 GitHub Release 标签不一致。');
        }

        $required = [
            'composer.json', 'composer.lock', 'beike/Config/beike.php', 'bootstrap/app.php',
            'public/index.php', 'vendor/autoload.php', 'vendor/composer/installed.php',
        ];
        foreach ($required as $path) {
            if (! array_key_exists($path, $manifest['files'])) {
                throw new RuntimeException("发布包缺少核心文件：{$path}");
            }
        }

        foreach ($manifest['files'] as $path => $sha256) {
            $normalized = $this->normalizePath((string) $path);
            if ($normalized !== $path || $this->isProtected($path)) {
                throw new RuntimeException("发布清单包含受保护路径：{$path}");
            }
            if (! preg_match('/^[a-f0-9]{64}$/', (string) $sha256)) {
                throw new RuntimeException("发布清单哈希无效：{$path}");
            }
            if (! isset($entries[$path]) || $entries[$path]['directory']) {
                throw new RuntimeException("发布清单文件不存在：{$path}");
            }
        }

        foreach ($entries as $path => $entry) {
            if ($path === self::MANIFEST || $entry['directory']) {
                continue;
            }
            if (! array_key_exists($path, $manifest['files'])) {
                throw new RuntimeException("更新包包含未声明文件：{$path}");
            }
        }

        $deletes         = (array) ($manifest['delete'] ?? []);
        $deleteCasePaths = [];
        $fileCasePaths   = array_fill_keys(array_map('strtolower', array_keys($manifest['files'])), true);
        foreach ($deletes as $path) {
            if (! is_string($path)) {
                throw new RuntimeException('发布清单删除路径无效。');
            }
            $normalized = $this->normalizePath((string) $path);
            $casePath   = strtolower($normalized);
            if ($normalized !== $path
                || $this->isProtected($normalized)
                || isset($fileCasePaths[$casePath])
                || isset($deleteCasePaths[$casePath])) {
                throw new RuntimeException("发布清单删除路径无效：{$path}");
            }
            $deleteCasePaths[$casePath] = true;
        }

        $minimumPhp = (string) data_get($manifest, 'requires.php', '8.2.0');
        if (! preg_match('/^\d+\.\d+(?:\.\d+)?$/', $minimumPhp) || version_compare(PHP_VERSION, $minimumPhp, '<')) {
            throw new RuntimeException("服务器 PHP 版本不满足更新要求：{$minimumPhp}+");
        }
        $minimumVersion = trim((string) data_get($manifest, 'requires.longos', ''));
        if ($minimumVersion !== '' && version_compare((string) config('beike.version', '0.0.0'), $minimumVersion, '<')) {
            throw new RuntimeException("必须先升级到 LONGOS {$minimumVersion} 后才能安装此版本。");
        }
    }

    private function normalizePath(string $path): string
    {
        if ($path !== trim($path) || str_contains($path, '\\') || strlen($path) > 4096) {
            throw new RuntimeException('更新包包含不规范路径。');
        }
        $path = rtrim($path, '/');
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/')
                         || preg_match('/^[A-Za-z]:/', $path) || preg_match('#(?:^|/)\.\.(?:/|$)#', $path)) {
            throw new RuntimeException('更新包包含不安全路径。');
        }

        $segments = explode('/', $path);
        if (in_array('', $segments, true) || in_array('.', $segments, true)) {
            throw new RuntimeException("更新包路径不规范：{$path}");
        }
        foreach ($segments as $segment) {
            $stem = strtoupper((string) strtok($segment, '.'));
            if (strlen($segment) > 255
                || preg_match('/[\x00-\x1F\x7F:*?"<>|]/', $segment)
                || str_ends_with($segment, '.')
                || str_ends_with($segment, ' ')
                || preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/', $stem)) {
                throw new RuntimeException("更新包路径不兼容安全文件系统：{$path}");
            }
        }

        return implode('/', $segments);
    }

    private function isProtected(string $path): bool
    {
        $path = strtolower(trim($path, '/'));
        foreach (self::PROTECTED as $protected) {
            $protected = strtolower($protected);
            if ($path === $protected || str_starts_with($path, $protected . '/')) {
                return true;
            }
        }

        return false;
    }

    private function isSymlink(ZipArchive $zip, int $index): bool
    {
        $operations = 0;
        $attributes = 0;
        if (! $zip->getExternalAttributesIndex($index, $operations, $attributes)) {
            return false;
        }

        return (($attributes >> 16) & 0170000) === 0120000;
    }

    /** @param array<string, mixed> $ledger */
    private function backupExisting(string $path, string $destination, string $backup, array &$ledger, string $bucket = 'replaced'): void
    {
        if (! is_file($destination)) {
            $ledger['created'][] = $path;

            return;
        }

        $backupFile = $backup . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $parent     = dirname($backupFile);
        if (! is_dir($parent) && ! @mkdir($parent, 0700, true) && ! is_dir($parent)) {
            throw new RuntimeException("无法创建备份目录：{$path}");
        }
        $this->copyDurably($destination, $backupFile, 0600, "无法备份现有文件：{$path}");
        $sha256 = hash_file('sha256', $backupFile);
        if (! is_string($sha256)) {
            throw new RuntimeException("无法计算代码备份校验值：{$path}");
        }
        $permissions              = @fileperms($destination);
        $ledger[$bucket][]        = $path;
        $ledger['backups'][$path] = $sha256;
        $ledger['modes'][$path]   = is_int($permissions) ? (($permissions & 0777) ?: $this->modeFor($path)) : $this->modeFor($path);
    }

    /** @param array<string, mixed> $ledger */
    private function writeLedger(string $backup, array $ledger): void
    {
        $this->validateLedger($ledger);
        $path      = $backup . DIRECTORY_SEPARATOR . 'ledger.json';
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $json      = json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $this->writeDurably($temporary, $json, 0600, '无法写入代码回滚清单，更新已停止。');
        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException('无法原子保存代码回滚清单，更新已停止。');
        }
        @chmod($path, 0600);
        $this->fsyncParent($path);
    }

    /** @param array<string, mixed> $ledger */
    private function validateLedger(array $ledger): void
    {
        $all = [];
        foreach (['replaced', 'created', 'deleted'] as $bucket) {
            if (! is_array($ledger[$bucket] ?? null)) {
                throw new RuntimeException('代码回滚清单格式无效。');
            }
            foreach ($ledger[$bucket] as $path) {
                if (! is_string($path)
                    || $this->normalizePath($path) !== $path
                    || $this->isProtected($path)) {
                    throw new RuntimeException('代码回滚清单包含不安全路径。');
                }
                $casePath = strtolower($path);
                if (isset($all[$casePath])) {
                    throw new RuntimeException('代码回滚清单包含重复或冲突路径。');
                }
                $all[$casePath] = $path;
            }
        }

        $schema = (int) ($ledger['schema'] ?? 1);
        if ($schema === 1) {
            return;
        }
        if ($schema !== 2 || ! is_array($ledger['backups'] ?? null) || ! is_array($ledger['modes'] ?? null)) {
            throw new RuntimeException('代码回滚清单版本无效。');
        }

        $required      = array_merge((array) $ledger['replaced'], (array) $ledger['deleted']);
        $metadataPaths = array_keys($ledger['backups']);
        $modePaths     = array_keys($ledger['modes']);
        sort($required, SORT_STRING);
        sort($metadataPaths, SORT_STRING);
        sort($modePaths, SORT_STRING);
        if ($required !== $metadataPaths || $required !== $modePaths) {
            throw new RuntimeException('代码回滚清单与备份文件不匹配。');
        }
        foreach ($ledger['backups'] as $path => $sha256) {
            if (! is_string($path)
                || ! preg_match('/^[a-f0-9]{64}$/', (string) $sha256)
                || ! is_int($ledger['modes'][$path] ?? null)
                || $ledger['modes'][$path] < 0
                || $ledger['modes'][$path] > 0777) {
                throw new RuntimeException('代码回滚清单的备份元数据无效。');
            }
        }
    }

    private function atomicCopy(string $source, string $destination, string $path, ?int $modeOverride = null): void
    {
        if (! is_file($source)) {
            throw new RuntimeException("更新源文件不存在：{$path}");
        }
        $parent = dirname($destination);
        if (! is_dir($parent) && ! @mkdir($parent, 0755, true) && ! is_dir($parent)) {
            throw new RuntimeException("无法创建目标目录：{$path}");
        }

        // Production images keep the project root non-writable so PHP cannot
        // rename privileged deploy directories. The two existing Composer metadata
        // files are group-writable and can be synchronized in place; runtime
        // PHP never loads them, and the rollback ledger was already fsynced.
        if (! is_writable($parent) && is_file($destination) && is_writable($destination)) {
            $this->lockedCopy($source, $destination, $path);
            if ($modeOverride !== null) {
                @chmod($destination, $modeOverride);
            }

            return;
        }

        $mode      = $modeOverride ?? (is_file($destination) ? (fileperms($destination) & 0777) : $this->modeFor($path));
        $temporary = $destination . '.longos-new-' . bin2hex(random_bytes(6));
        $this->copyDurably($source, $temporary, $mode ?: $this->modeFor($path), "无法写入更新文件：{$path}");
        if (! @rename($temporary, $destination)) {
            @unlink($temporary);

            throw new RuntimeException("无法原子替换更新文件：{$path}");
        }
        $this->fsyncParent($destination);
    }

    private function fsyncParent(string $path): void
    {
        if (! function_exists('fsync')) {
            return;
        }
        $directory = @fopen(dirname($path), 'rb');
        if (is_resource($directory)) {
            @fsync($directory);
            fclose($directory);
        }
    }

    private function copyDurably(string $source, string $destination, int $mode, string $error): void
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
        @chmod($destination, $mode);
    }

    private function writeDurably(string $path, string $contents, int $mode, string $error): void
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
            fclose($handle);
            @unlink($path);

            throw $exception;
        }
        fclose($handle);
        @chmod($path, $mode);
    }

    private function lockedCopy(string $source, string $destination, string $path): void
    {
        $input  = @fopen($source, 'rb');
        $output = @fopen($destination, 'c+b');
        if (! is_resource($input) || ! is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }

            throw new RuntimeException("无法写入根目录更新文件：{$path}");
        }

        try {
            if (! flock($output, LOCK_EX) || ! ftruncate($output, 0) || fseek($output, 0) !== 0) {
                throw new RuntimeException("无法锁定根目录更新文件：{$path}");
            }
            $written = stream_copy_to_stream($input, $output);
            if ($written !== filesize($source) || ! fflush($output)) {
                throw new RuntimeException("根目录更新文件写入不完整：{$path}");
            }
            if (function_exists('fsync')) {
                @fsync($output);
            }
        } finally {
            @flock($output, LOCK_UN);
            fclose($input);
            fclose($output);
        }
    }

    private function modeFor(string $path): int
    {
        return $path === 'artisan' || $path === 'install.sh' || str_starts_with($path, 'scripts/') && str_ends_with($path, '.sh')
            ? 0750
            : 0644;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
