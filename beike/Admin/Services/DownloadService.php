<?php

namespace Beike\Admin\Services;

use App\Saas\Support\SensitiveValueRedactor;
use Beike\Exceptions\PluginDownloadException;
use Beike\Facades\BeikeHttp\Facade\Http;
use Exception;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http as LaravelHttp;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class DownloadService
{
    /**
     * 获取配置值
     *
     * @param string $key     配置键
     * @param mixed  $default 默认值
     * @return mixed
     */
    private function getConfig(string $key, $default = null)
    {
        return config("download.{$key}", $default);
    }

    /**
     * 获取插件配置
     *
     * @param string $key
     * @return mixed
     */
    private function getPluginConfig(string $key)
    {
        return $this->getConfig("plugin.{$key}");
    }

    /**
     * 获取安全配置
     *
     * @param string $key
     * @return mixed
     */
    private function getSecurityConfig(string $key)
    {
        return $this->getConfig("security.{$key}");
    }

    /**
     * 获取权限配置
     *
     * @param string $key
     * @return mixed
     */
    private function getPermissionConfig(string $key)
    {
        return $this->getConfig("permissions.{$key}");
    }

    /**
     * 统一的插件下载方法
     *
     * @param string $pluginCode 插件代码
     * @param string $source     下载源 ('local' 或 'oss')
     * @return bool 下载成功状态
     * @throws PluginDownloadException
     */
    public function download(string $pluginCode, string $source = 'local'): bool
    {
        $this->validateDownloadSource($source);

        return $this->executeWithLock($pluginCode, function () use ($pluginCode, $source) {
            $datetime    = date('Y-m-d');
            $apiEndPoint = $this->getApiEndpoint($source, $pluginCode);
            $pluginPath  = $this->getPluginConfig('plugin_dir') . "/{$pluginCode}-{$datetime}.zip";
            $pluginZip   = storage_path('app/' . $pluginPath);

            try {
                // 根据下载源选择下载策略
                if ($source === 'local') {
                    $this->downloadFromLocal($apiEndPoint, $pluginPath, $pluginCode);
                } else {
                    $this->downloadFromOSSFile($apiEndPoint, $pluginPath, $pluginCode);
                }

                // 验证文件内容安全性
                $this->validateFileContent($pluginZip);

                // 获取插件信息
                $info = $this->getPluginInfo($pluginZip);

                if (! $info['is_beikeshop_plugin']) {
                    throw new PluginDownloadException(
                        __('admin.download.unrecognized_beikeshop_plugin'),
                        ['plugin_code' => $pluginCode, 'source' => $source]
                    );
                }

                // 解压插件
                $this->extractPlugin($pluginZip, $info);

                $this->logDownloadEvent('info', "Plugin downloaded successfully from {$source}", [
                    'plugin_code' => $pluginCode,
                    'file_path'   => $pluginZip,
                    'file_size'   => filesize($pluginZip),
                    'source'      => $source,
                ]);

                return true;

            } catch (PluginDownloadException $e) {
                throw $e;
            } catch (Exception $e) {
                $this->handleDownloadError("{$source}_download", $e, [
                    'plugin_code' => $pluginCode,
                    'file_path'   => $pluginZip ?? null,
                    'source'      => $source,
                ]);
            } finally {
                $this->cleanupTempFile($pluginZip ?? null);
            }
        });
    }

    /**
     * Download plugin from local API (向后兼容)
     *
     * @param  string                  $pluginCode Plugin code
     * @throws PluginDownloadException
     */
    public function fromLocal(string $pluginCode): void
    {
        $this->download($pluginCode, 'local');
    }

    /**
     * Download plugin from OSS (向后兼容)
     *
     * @param string $pluginCode Plugin code
     * @return bool Download success status
     * @throws PluginDownloadException
     */
    public function fromOSS(string $pluginCode): bool
    {
        return $this->download($pluginCode, 'oss');
    }

    /**
     * 使用默认源下载插件
     *
     * @param string $pluginCode 插件代码
     * @return bool 下载成功状态
     * @throws PluginDownloadException
     */
    public function downloadWithDefaultSource(string $pluginCode): bool
    {
        $defaultSource = $this->getConfig('default_source', 'local');

        return $this->download($pluginCode, $defaultSource);
    }

    /**
     * 获取可用的下载源列表
     *
     * @return array 可用下载源
     */
    public function getAvailableSources(): array
    {
        $sources   = $this->getConfig('download_sources', []);
        $available = [];

        foreach ($sources as $key => $config) {
            if ($config['enabled'] ?? false) {
                $available[$key] = [
                    'name'        => $config['name'],
                    'description' => $config['description'],
                ];
            }
        }

        return $available;
    }

    /**
     * 验证下载源
     *
     * @param  string                  $source 下载源
     * @throws PluginDownloadException
     */
    private function validateDownloadSource(string $source): void
    {
        $allowedSources = ['local', 'oss'];
        if (! in_array($source, $allowedSources)) {
            throw new PluginDownloadException(
                __('admin.download.invalid_download_source', ['source' => $source]),
                ['source' => $source, 'allowed_sources' => $allowedSources]
            );
        }
    }

    /**
     * 获取API端点
     *
     * @param string $source     下载源
     * @param string $pluginCode 插件代码
     * @return string API端点
     */
    private function getApiEndpoint(string $source, string $pluginCode): string
    {
        $endpointTemplate = $this->getConfig("api.{$source}_endpoint");

        return str_replace('{plugin}', $pluginCode, $endpointTemplate);
    }

    /**
     * 从本地API下载
     *
     * @param  string                  $apiEndPoint API端点
     * @param  string                  $pluginPath  插件路径
     * @param  string                  $pluginCode  插件代码
     * @throws PluginDownloadException
     */
    private function downloadFromLocal(string $apiEndPoint, string $pluginPath, string $pluginCode): void
    {
        // 下载插件文件
        $content = Http::sendGet($apiEndPoint, ['timeout' => null], 'body');
        if (empty($content)) {
            throw new PluginDownloadException(
                __('admin.download.download_failed_empty_content'),
                ['plugin_code' => $pluginCode, 'source' => 'local']
            );
        }

        // 验证文件内容
        $this->validateZipContent($content);

        // 保存到本地存储
        Storage::disk('local')->put($pluginPath, $content);
    }

    /**
     * 从OSS下载插件文件
     *
     * @param  string                  $apiEndPoint API端点
     * @param  string                  $pluginPath  插件路径
     * @param  string                  $pluginCode  插件代码
     * @throws PluginDownloadException
     */
    private function downloadFromOSSFile(string $apiEndPoint, string $pluginPath, string $pluginCode): void
    {
        $this->downloadPluginFile($apiEndPoint, $pluginPath);
    }

    /**
     * 执行带锁的操作
     *
     * @param string   $pluginCode 插件代码
     * @param callable $callback   回调函数
     * @return mixed 回调函数的返回值
     * @throws PluginDownloadException
     */
    private function executeWithLock(string $pluginCode, callable $callback)
    {
        $this->validatePluginCode($pluginCode);

        $lockKey = $this->getPluginConfig('download_lock_prefix') . $pluginCode;
        $lock    = Cache::lock($lockKey, max(30, (int) $this->getPluginConfig('lock_ttl')));

        if (! $lock->get()) {
            throw new PluginDownloadException(
                __('admin.download.plugin_download_in_progress', ['plugin' => $pluginCode]),
                ['plugin_code' => $pluginCode, 'lock_key' => $lockKey]
            );
        }

        try {
            return $callback();
        } finally {
            // Laravel owner tokens prevent an expired lock holder from
            // releasing a newer process' lock.
            $lock->release();
        }
    }

    /**
     * 统一错误处理
     *
     * @param  string                  $operation 操作名称
     * @param  Exception               $e         异常对象
     * @param  array                   $context   上下文数据
     * @throws PluginDownloadException
     */
    private function handleDownloadError(string $operation, Exception $e, array $context = []): void
    {
        $safeError  = SensitiveValueRedactor::redact($e->getMessage());
        $logContext = array_merge($context, [
            'operation'     => $operation,
            'error_message' => $safeError,
            'error_code'    => $e->getCode(),
            'timestamp'     => now()->toISOString(),
        ]);

        $this->logDownloadEvent('error', "Plugin download failed: {$operation}", $logContext);

        throw new PluginDownloadException(
            __('admin.download.operation_failed', ['operation' => $operation]),
            $logContext,
            $e->getCode(),
            $e
        );
    }

    /**
     * 结构化日志记录
     *
     * @param string $level   日志级别
     * @param string $message 日志消息
     * @param array  $context 上下文数据
     */
    private function logDownloadEvent(string $level, string $message, array $context = []): void
    {
        $structuredContext = array_merge($context, [
            'service'     => 'DownloadService',
            'version'     => '1.0.0',
            'environment' => app()->environment(),
            'request_id'  => request()->header('X-Request-ID'),
        ]);

        Log::channel('plugin_download')->{$level}($message, $structuredContext);
    }

    /**
     * Download plugin file from OSS
     *
     * @param  string                  $apiEndPoint API endpoint
     * @param  string                  $pluginPath  Plugin path
     * @throws PluginDownloadException
     */
    private function downloadPluginFile(string $apiEndPoint, string $pluginPath): void
    {
        try {
            // 第一步：获取OSS下载链接
            $downloadInfo   = Http::sendGet($apiEndPoint);
            $status         = data_get($downloadInfo, 'status', 'fail');
            $message        = data_get($downloadInfo, 'message', '');
            $downloadUrl    = data_get($downloadInfo, 'data.url', null);

            if ($status === 'fail') {
                throw new PluginDownloadException($message, ['api_endpoint' => $apiEndPoint]);
            }

            // 验证返回的数据结构
            if (is_null($downloadUrl)) {
                throw new PluginDownloadException(
                    __('admin.download.missing_download_url'),
                    ['api_endpoint' => $apiEndPoint]
                );
            }

            // 第二步：从OSS下载ZIP文件
            $zipContent = $this->downloadFromOss($downloadUrl);

            if (empty($zipContent)) {
                throw new PluginDownloadException(
                    __('admin.download.oss_download_empty_content'),
                    ['download_url' => $this->safeDownloadUrlForLog($downloadUrl)]
                );
            }

            // 验证文件是否为有效的ZIP格式
            $this->validateZipContent($zipContent);

            // 第三步：保存到本地存储
            Storage::disk('local')->put($pluginPath, $zipContent);

            $this->logDownloadEvent('info', 'Plugin file downloaded from OSS', [
                'local_path'   => $pluginPath,
                'file_size'    => strlen($zipContent),
            ]);

        } catch (PluginDownloadException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new PluginDownloadException(
                __('admin.download.download_plugin_failed') . ': ' . $e->getMessage(),
                ['api_endpoint' => $apiEndPoint, 'original_error' => $e->getMessage()],
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Download file from OSS
     *
     * @param string $downloadUrl OSS download URL
     * @return string File content
     * @throws PluginDownloadException
     */
    private function downloadFromOss(string $downloadUrl): string
    {
        try {
            [$downloadUrl, $host, $port, $address] = $this->validateDownloadUrl($downloadUrl);
            $pinnedAddress                         = str_contains($address, ':') ? "[{$address}]" : $address;

            $response = LaravelHttp::withOptions([
                'verify'          => config('beike.http_verify_ssl', true),
                'stream'          => true,
                'allow_redirects' => false,
                'connect_timeout' => 10,
                'timeout'         => max(10, (int) $this->getPluginConfig('download_timeout')),
                // Pin the address which was just validated. This closes the
                // DNS-rebinding window while preserving TLS host validation.
                'curl'            => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinnedAddress}"]],
            ])->get($downloadUrl);

            if (! $response->successful()) {
                throw new PluginDownloadException(
                    __('admin.download.oss_download_failed_status', ['status' => $response->status()]),
                    ['download_url' => $this->safeDownloadUrlForLog($downloadUrl), 'status_code' => $response->status()]
                );
            }

            $maximumBytes  = max(1024, (int) $this->getPluginConfig('max_file_size'));
            $contentLength = (int) $response->header('Content-Length');
            if ($contentLength > $maximumBytes) {
                throw new PluginDownloadException(
                    __('admin.download.file_too_large', ['size' => $maximumBytes / 1024 / 1024]),
                    ['file_size' => $contentLength, 'max_size' => $maximumBytes]
                );
            }

            $content = '';
            $stream  = $response->toPsrResponse()->getBody();
            while (! $stream->eof()) {
                $remaining = $maximumBytes + 1 - strlen($content);
                $chunk     = $stream->read(min(8192, max(1, $remaining)));
                if ($chunk === '') {
                    throw new PluginDownloadException(__('admin.download.oss_download_empty_file'));
                }
                $content .= $chunk;
                if (strlen($content) > $maximumBytes) {
                    throw new PluginDownloadException(
                        __('admin.download.file_too_large', ['size' => $maximumBytes / 1024 / 1024]),
                        ['file_size' => strlen($content), 'max_size' => $maximumBytes]
                    );
                }
            }

            if (empty($content)) {
                throw new PluginDownloadException(
                    __('admin.download.oss_download_empty_file'),
                    ['download_url' => $this->safeDownloadUrlForLog($downloadUrl)]
                );
            }

            return $content;

        } catch (PluginDownloadException $e) {
            throw $e;
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $safeError = SensitiveValueRedactor::redact($e->getMessage());

            throw new PluginDownloadException(
                __('admin.download.oss_download_request_failed'),
                ['download_url' => $this->safeDownloadUrlForLog($downloadUrl), 'original_error' => $safeError],
                $e->getCode(),
                $e
            );
        } catch (Exception $e) {
            $safeError = SensitiveValueRedactor::redact($e->getMessage());

            throw new PluginDownloadException(
                __('admin.download.oss_download_exception'),
                ['download_url' => $this->safeDownloadUrlForLog($downloadUrl), 'original_error' => $safeError],
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * 验证ZIP文件内容
     *
     * @param  string                  $content 文件内容
     * @throws PluginDownloadException
     */
    private function validateZipContent(string $content): void
    {
        // 检查ZIP文件头部签名
        if (strlen($content) < 4) {
            throw new PluginDownloadException(
                __('admin.download.file_too_short'),
                ['content_length' => strlen($content)]
            );
        }

        // ZIP文件的魔数签名
        $zipSignatures = [
            "\x50\x4B\x03\x04", // 标准ZIP文件
            "\x50\x4B\x05\x06", // 空ZIP文件
            "\x50\x4B\x07\x08", // 跨卷ZIP文件
        ];

        $fileHeader = substr($content, 0, 4);
        $isValidZip = false;

        foreach ($zipSignatures as $signature) {
            if ($fileHeader === $signature) {
                $isValidZip = true;

                break;
            }
        }

        if (! $isValidZip) {
            throw new PluginDownloadException(
                __('admin.download.invalid_zip_format'),
                ['file_header' => bin2hex($fileHeader)]
            );
        }

        // 检查文件大小限制
        $maxSize = $this->getPluginConfig('max_file_size');
        if (strlen($content) > $maxSize) {
            throw new PluginDownloadException(
                __('admin.download.file_too_large', ['size' => $maxSize / 1024 / 1024]),
                ['file_size' => strlen($content), 'max_size' => $maxSize]
            );
        }

        // 检查最小文件大小（避免空文件或损坏文件）
        $minSize = $this->getPluginConfig('min_file_size');
        if (strlen($content) < $minSize) {
            throw new PluginDownloadException(
                __('admin.download.file_too_small'),
                ['file_size' => strlen($content), 'min_size' => $minSize]
            );
        }
    }

    /**
     * 验证文件内容安全性
     *
     * @param  string                  $filePath 文件路径
     * @throws PluginDownloadException
     */
    private function validateFileContent(string $filePath): void
    {
        // Archive structure is always security-sensitive and must never be
        // disabled by an optional content-scanning setting.
        $this->scanZipForMaliciousFiles(
            $filePath,
            (bool) $this->getSecurityConfig('scan_for_malicious_files')
        );

        if (! $this->getSecurityConfig('validate_file_content')) {
            return;
        }

        // 检查MIME类型
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        $allowedMimeTypes = $this->getSecurityConfig('allowed_mime_types');
        if (! in_array($mimeType, $allowedMimeTypes)) {
            throw new PluginDownloadException(
                __('admin.download.invalid_file_type'),
                ['mime_type' => $mimeType, 'allowed_types' => $allowedMimeTypes]
            );
        }

    }

    /**
     * 扫描ZIP文件中的恶意文件
     *
     * @param  string                  $zipPath ZIP文件路径
     * @throws PluginDownloadException
     */
    private function scanZipForMaliciousFiles(string $zipPath, bool $scanExtensions = true): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new PluginDownloadException(
                __('admin.download.cannot_open_zip'),
                ['plugin_zip' => $zipPath]
            );
        }

        try {
            $dangerousExtensions = $this->getSecurityConfig('dangerous_extensions');
            $maximumFiles        = max(1, (int) $this->getSecurityConfig('max_files'));
            $maximumSize         = max(1024, (int) $this->getSecurityConfig('max_extracted_size'));
            $maximumRatio        = max(1, (float) $this->getSecurityConfig('max_compression_ratio'));
            $totalSize           = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (! is_array($stat) || ! isset($stat['name'], $stat['size'], $stat['comp_size'])) {
                    throw new PluginDownloadException(__('admin.download.invalid_zip_format'));
                }

                $filename = (string) $stat['name'];
                if (! $this->isSafeArchivePath($filename)) {
                    throw new PluginDownloadException(
                        __('admin.download.path_traversal_detected', ['file' => $filename]),
                        ['malicious_file' => $filename]
                    );
                }

                $operatingSystem = 0;
                $attributes      = 0;
                if ($zip->getExternalAttributesIndex($i, $operatingSystem, $attributes)
                    && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new PluginDownloadException(
                        __('admin.download.dangerous_file_detected', ['file' => $filename]),
                        ['dangerous_file' => $filename, 'reason' => 'symbolic_link']
                    );
                }

                if ($i + 1 > $maximumFiles) {
                    throw new PluginDownloadException(__('admin.download.invalid_zip_format'), [
                        'reason'    => 'too_many_files',
                        'max_files' => $maximumFiles,
                    ]);
                }

                $size       = max(0, (int) $stat['size']);
                $compressed = max(0, (int) $stat['comp_size']);
                $totalSize += $size;
                if ($totalSize > $maximumSize) {
                    throw new PluginDownloadException(__('admin.download.file_too_large'), [
                        'reason'            => 'expanded_size',
                        'expanded_size'     => $totalSize,
                        'max_expanded_size' => $maximumSize,
                    ]);
                }
                if ($size > 0 && $compressed > 0 && ($size / $compressed) > $maximumRatio) {
                    throw new PluginDownloadException(__('admin.download.invalid_zip_format'), [
                        'reason' => 'compression_ratio',
                        'file'   => $filename,
                    ]);
                }

                // 检查危险文件扩展名
                if ($scanExtensions) {
                    $extension = strtolower('.' . pathinfo(rtrim($filename, '/'), PATHINFO_EXTENSION));
                    foreach ($dangerousExtensions as $ext) {
                        if ($extension === strtolower((string) $ext)) {
                            throw new PluginDownloadException(
                                __('admin.download.dangerous_file_detected', ['file' => $filename]),
                                ['dangerous_file' => $filename, 'extension' => $ext]
                            );
                        }
                    }
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function isSafeArchivePath(string $filename): bool
    {
        if ($filename === ''
            || strlen($filename) > 512
            || str_contains($filename, "\0")
            || str_contains($filename, '\\')
            || str_starts_with($filename, '/')
            || preg_match('/^[A-Za-z]:/', $filename)
            || str_contains($filename, ':')) {
            return false;
        }

        $segments = explode('/', rtrim($filename, '/'));

        return $segments !== [] && array_filter(
            $segments,
            static fn (string $segment): bool => $segment === '' || $segment === '.' || $segment === '..'
        ) === [];
    }

    /**
     * 解压插件文件
     *
     * @param  string                  $pluginZip 插件ZIP文件路径
     * @param  array                   $info      插件信息
     * @throws PluginDownloadException
     */
    private function extractPlugin(string $pluginZip, array $info): void
    {
        $zip = new ZipArchive;

        if ($zip->open($pluginZip) !== true) {
            throw new PluginDownloadException(
                __('admin.download.cannot_open_zip'),
                ['plugin_zip' => $pluginZip]
            );
        }

        try {
            if ($info['is_error']) {
                $this->handleErrorPlugin($zip, $info);
            } else {
                $this->handleNormalPlugin($zip);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * 处理有错误的插件
     *
     * @param  ZipArchive              $zip  ZIP文件对象
     * @param  array                   $info 插件信息
     * @throws PluginDownloadException
     */
    private function handleErrorPlugin(ZipArchive $zip, array $info): void
    {
        $dirInfo = $info['dir_info'] ?? [];

        if (count($dirInfo) <= 1) {
            throw new PluginDownloadException(
                __('admin.download.plugin_dir_info_incomplete'),
                ['dir_info' => $dirInfo]
            );
        }

        $targetDir     = $dirInfo[1];
        $pluginBaseDir = base_path($this->getPluginConfig('plugin_dir'));

        if ($info['error_dir']) {
            // 文件跟命名空间不符合的插件
            $this->extractAndRename($zip, $pluginBaseDir, $info['error_dir'], $targetDir);
        } else {
            // 散开的文件
            $this->extractToTargetDir($zip, $targetDir);
        }
    }

    /**
     * 处理正常的插件
     *
     * @param  ZipArchive              $zip ZIP文件对象
     * @throws PluginDownloadException
     */
    private function handleNormalPlugin(ZipArchive $zip): void
    {
        $pluginDir = base_path($this->getPluginConfig('plugin_dir'));

        if (! $zip->extractTo($pluginDir)) {
            throw new PluginDownloadException(
                __('admin.download.extract_to_target_failed'),
                ['plugin_dir' => $pluginDir]
            );
        }

        // 设置解压后文件的权限
        $this->setExtractedFilePermissions($pluginDir);
    }

    /**
     * 解压并重命名目录
     *
     * @param  ZipArchive              $zip           ZIP文件对象
     * @param  string                  $pluginBaseDir 插件基础目录
     * @param  string                  $errorDir      错误目录名
     * @param  string                  $targetDir     目标目录名
     * @throws PluginDownloadException
     */
    private function extractAndRename(ZipArchive $zip, string $pluginBaseDir, string $errorDir, string $targetDir): void
    {
        if (! $zip->extractTo($pluginBaseDir)) {
            throw new PluginDownloadException(
                __('admin.download.extract_plugin_failed'),
                ['plugin_base_dir' => $pluginBaseDir]
            );
        }

        $errorPath  = $pluginBaseDir . '/' . $errorDir;
        $targetPath = $pluginBaseDir . '/' . $targetDir;

        if (! file_exists($errorPath)) {
            throw new PluginDownloadException(
                __('admin.download.source_dir_not_exists', ['path' => $errorPath]),
                ['error_path' => $errorPath, 'target_path' => $targetPath]
            );
        }

        if (file_exists($targetPath)) {
            // 如果目标目录已存在，先删除
            (new Filesystem)->deleteDirectory($targetPath);
        }

        if (! rename($errorPath, $targetPath)) {
            throw new PluginDownloadException(
                __('admin.download.rename_plugin_dir_failed'),
                ['error_path' => $errorPath, 'target_path' => $targetPath]
            );
        }

        // 设置重命名后文件的权限
        $this->setExtractedFilePermissions($targetPath);
    }

    /**
     * 解压到指定目录
     *
     * @param  ZipArchive              $zip       ZIP文件对象
     * @param  string                  $targetDir 目标目录名
     * @throws PluginDownloadException
     */
    private function extractToTargetDir(ZipArchive $zip, string $targetDir): void
    {
        $pluginDir = base_path($this->getPluginConfig('plugin_dir') . '/' . $targetDir);

        if (! is_dir($pluginDir)) {
            if (! (new Filesystem)->makeDirectory($pluginDir, $this->getPermissionConfig('directory'), true)) {
                throw new PluginDownloadException(
                    __('admin.download.create_plugin_dir_failed', ['path' => $pluginDir]),
                    ['plugin_dir' => $pluginDir]
                );
            }
        }

        if (! $zip->extractTo($pluginDir)) {
            throw new PluginDownloadException(
                __('admin.download.extract_to_target_failed'),
                ['plugin_dir' => $pluginDir]
            );
        }

        // 设置解压后文件的权限
        $this->setExtractedFilePermissions($pluginDir);
    }

    /**
     * Clean up temporary files
     *
     * @param string|null $filePath File path
     */
    private function cleanupTempFile(?string $filePath): void
    {
        if ($filePath && file_exists($filePath)) {
            if (! unlink($filePath)) {
                Log::warning('Failed to delete temporary file', ['file_path' => $filePath]);
            }
        }
    }

    /**
     * Get plugin directory by parsing namespace from content
     *
     * @param string $content File content
     * @return array Plugin directory parts
     */
    public function getPluginDir(string $content): array
    {
        preg_match('/namespace\s+([^\s;]+);/', $content, $matches);

        if (empty($matches[1])) {
            return [];
        }

        return explode('\\', $matches[1]);
    }

    /**
     * Get plugin information from ZIP file
     *
     * @param string $zipFile ZIP file path
     * @return array Plugin information
     * @throws PluginDownloadException
     */
    public function getPluginInfo(string $zipFile): array
    {
        $dir_info            = [];
        $configInfo          = [];
        $error_dir           = '';
        $is_error            = false;
        $is_beikeshop_plugin = false;

        $zip = new ZipArchive;

        if ($zip->open($zipFile) === true) {
            // 单次遍历获取所有需要的信息
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat          = $zip->statIndex($i);
                $name          = $stat['name'];
                $fileExtension = pathinfo($name);

                // 检查config.json文件
                if ($fileExtension['basename'] === 'config.json') {
                    $configInfo = $fileExtension;
                }

                // 检查PHP文件并解析命名空间
                if (pathinfo($name, PATHINFO_EXTENSION) === 'php' && empty($dir_info)) {
                    $content = $zip->getFromIndex($i);
                    preg_match('/namespace\s+([^\s;]+);/', $content, $matches);
                    if ($matches) {
                        $dir_info = explode('\\', $matches[1]);
                    }
                }

                // 如果已经找到config.json和命名空间信息，可以提前退出
                if (! empty($configInfo) && ! empty($dir_info)) {
                    break;
                }
            }

            // 处理插件信息逻辑
            if (isset($configInfo['dirname'])) {
                $dirName = $configInfo['dirname'];
                if ($dirName === '.') {
                    $is_error = true;
                } else {
                    if (count($dir_info) > 1) {
                        $_pluginDir = $dir_info[1];
                        if (rtrim($dirName, '/') !== $_pluginDir) {
                            $is_error  = true;
                            $error_dir = rtrim($dirName, '/');
                        }
                    } else {
                        $is_beikeshop_plugin = true;
                    }
                }
            }

            if (count($dir_info) > 1 && $dir_info[0] === 'Plugin') {
                $is_beikeshop_plugin = true;
            }

            $zip->close();
        } else {
            throw new PluginDownloadException(
                __('admin.download.cannot_open_zip_or_not_exists'),
                ['zip_file' => $zipFile]
            );
        }

        return [
            'is_beikeshop_plugin' => $is_beikeshop_plugin,
            'is_error'            => $is_error,
            'error_dir'           => $error_dir,
            'dir_info'            => $dir_info,
        ];
    }

    /**
     * Validate plugin code format
     *
     * @param  string                  $pluginCode Plugin code
     * @throws PluginDownloadException
     */
    private function validatePluginCode(string $pluginCode): void
    {
        if (empty($pluginCode) || ! preg_match('/^[a-zA-Z0-9_-]+$/', $pluginCode)) {
            throw new PluginDownloadException(
                __('admin.download.invalid_plugin_code'),
                ['plugin_code' => $pluginCode]
            );
        }
    }

    /**
     * Validate download URL for security
     *
     * @param  string                  $downloadUrl Download URL
     * @throws PluginDownloadException
     */
    private function validateDownloadUrl(string $downloadUrl): array
    {
        // 验证URL格式
        if (! filter_var($downloadUrl, FILTER_VALIDATE_URL)) {
            throw new PluginDownloadException(
                __('admin.download.invalid_download_url_format'),
                ['download_url' => $this->safeDownloadUrlForLog($downloadUrl)]
            );
        }

        // 解析URL获取域名
        $parsedUrl = parse_url($downloadUrl);
        if (! $parsedUrl || ! isset($parsedUrl['host'])) {
            throw new PluginDownloadException(
                __('admin.download.cannot_parse_download_url_domain'),
                ['download_url' => $this->safeDownloadUrlForLog($downloadUrl)]
            );
        }

        $domain = trim(strtolower(rtrim((string) $parsedUrl['host'], '.')), '[]');

        if (strtolower((string) ($parsedUrl['scheme'] ?? '')) !== 'https'
            || isset($parsedUrl['user'])
            || isset($parsedUrl['pass'])
            || $domain === ''
            || ! preg_match('/^[a-z0-9.:-]+$/i', $domain)) {
            throw new PluginDownloadException(
                __('admin.download.download_url_must_https'),
                ['download_url' => $this->safeDownloadUrlForLog($downloadUrl)]
            );
        }

        $port = (int) ($parsedUrl['port'] ?? 443);
        if ($port !== 443) {
            throw new PluginDownloadException(
                __('admin.download.download_url_must_https'),
                ['download_url' => $this->safeDownloadUrlForLog($downloadUrl), 'port' => $port]
            );
        }

        $addresses = $this->resolvePublicAddresses($domain);
        if ($addresses === []) {
            throw new PluginDownloadException(
                __('admin.download.cannot_parse_download_url_domain'),
                ['download_url' => $this->safeDownloadUrlForLog($downloadUrl)]
            );
        }

        return [$downloadUrl, $domain, $port, $addresses[0]];
    }

    /** @return array<int, string> */
    private function resolvePublicAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicAddress($host) ? [$host] : [];
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! is_string($address) || ! $this->isPublicAddress($address)) {
                return [];
            }
            $addresses[] = $address;
        }

        usort($addresses, static fn (string $left, string $right): int => str_contains($left, ':') <=> str_contains($right, ':'));

        return array_values(array_unique($addresses));
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function safeDownloadUrlForLog(string $downloadUrl): string
    {
        $parts = parse_url($downloadUrl);
        if (! is_array($parts) || empty($parts['host'])) {
            return '[invalid-url]';
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . ($parts['path'] ?? '');
    }

    /**
     * Set proper permissions for extracted files
     *
     * @param string $path Path to set permissions for
     */
    private function setExtractedFilePermissions(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        $this->setPermissionsRecursively($path);

        Log::info('File permissions set for extracted plugin', [
            'path' => $path,
        ]);
    }

    /**
     * Recursively set permissions for files and directories
     *
     * @param string $path Path to process
     */
    private function setPermissionsRecursively(string $path): void
    {
        if (is_dir($path)) {
            // 设置目录权限
            @chmod($path, $this->getPermissionConfig('directory'));

            // 递归处理子目录和文件
            $items = scandir($path);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $itemPath = $path . DIRECTORY_SEPARATOR . $item;
                $this->setPermissionsRecursively($itemPath);
            }
        } else {
            // 设置文件权限
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            // 可执行文件使用可执行权限
            if (in_array($extension, ['sh', 'bat', 'exe', 'php']) ||
                is_executable($path)                              ||
                preg_match('/\.(sh|bat|exe)$/i', $path)) {
                @chmod($path, $this->getPermissionConfig('executable'));
            } else {
                @chmod($path, $this->getPermissionConfig('file'));
            }
        }
    }
}
