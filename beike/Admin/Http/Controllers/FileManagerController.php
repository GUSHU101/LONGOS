<?php

namespace Beike\Admin\Http\Controllers;

use Beike\Admin\Http\Requests\UploadRequest;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class FileManagerController extends Controller
{
    protected $fileManagerService;

    public function __construct()
    {
        $class = hook_filter('controller.file_manager.construct', \Beike\Admin\Services\FileManagerService::class);

        $this->fileManagerService = new $class;
    }

    /**
     * 获取文件夹和文件列表
     * @return mixed
     * @throws Exception
     */
    public function index(): mixed
    {
        $data              = $this->fileManagerService->getDirectories();
        $data              = hook_filter('admin.file_manager.index.data', $data);
        $data              = is_array($data) ? $data : [];
        $limits            = array_filter([
            $this->convertToBytes(ini_get('upload_max_filesize')),
            $this->convertToBytes(ini_get('post_max_size')),
            50 * 1024 * 1024,
        ], static fn (int $limit): bool => $limit > 0);
        $maxSizeBytes      = $limits === [] ? 50 * 1024 * 1024 : min($limits);
        $uploadMaxLabel    = $this->formatBytes($maxSizeBytes);

        return view('admin::pages.file_manager.index', [
            'directories'    => $data,
            'maxSizeBytes'   => $maxSizeBytes,
            'uploadMaxLabel' => $uploadMaxLabel,
        ]);
    }

    /**
     * 获取某个文件夹下面的文件列表
     *
     * @param Request $request
     * @return mixed
     * @throws Exception
     */
    public function getFiles(Request $request): mixed
    {
        $baseFolder = $request->get('base_folder', '/');
        $keyword    = trim((string) $request->get('keyword', ''));
        $sort       = in_array($request->get('sort'), ['created', 'name'], true) ? $request->get('sort') : 'created';
        $order      = in_array($request->get('order'), ['asc', 'desc'], true) ? $request->get('order') : 'desc';
        $mediaType  = in_array($request->get('mime'), ['image', 'video'], true) ? $request->get('mime') : null;
        $page       = max(1, (int) $request->get('page', 1));
        $perPage    = min(100, max(1, (int) $request->get('per_page', 20)));

        $data = $this->fileManagerService->getFiles($baseFolder, $keyword, $sort, $order, $page, $perPage, $mediaType);

        return hook_filter('admin.file_manager.files.data', $data);
    }

    /**
     * 获取文件夹列表
     * @param Request $request
     * @return mixed
     */
    public function getDirectories(Request $request): mixed
    {
        $baseFolder = $request->get('base_folder');
        $data       = $this->fileManagerService->getDirectories();

        return hook_filter('admin.file_manager.directories.data', $data);
    }

    /**
     * 创建文件夹
     * POST      /admin/file_manager
     * @throws Exception
     */
    public function createDirectory(Request $request): JsonResponse
    {
        try {
            $folderName = $request->get('name');
            $this->fileManagerService->createDirectory($folderName);

            return json_success(trans('common.created_success'));
        } catch (Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    /**
     * 文件或文件夹改名
     * PUT       /admin/file_manager/rename
     * @throws Exception
     */
    public function rename(Request $request): JsonResponse
    {
        try {
            // 验证请求参数
            $request->validate([
                'origin_name' => 'required|string|max:255',
                'new_name'    => 'required|string|max:255',
            ]);

            $originPath = $request->get('origin_name');
            $newName    = $request->get('new_name');

            // 基本的路径验证
            if (str_contains($originPath, '..') || str_contains($newName, '..')) {
                throw new \Exception(trans('admin/file_manager.invalid_path'));
            }

            // 验证新文件名的安全性
            if (! $this->isValidFileName($newName)) {
                throw new \Exception(trans('admin/file_manager.invalid_filename'));
            }

            $this->fileManagerService->updateName($originPath, $newName);

            // 记录重命名操作
            \Log::info('File renamed', [
                'origin_name' => $originPath,
                'new_name'    => $newName,
                'user_id'     => auth()->id(),
                'ip'          => $request->ip(),
            ]);

            return json_success(trans('common.updated_success'));
        } catch (Exception $e) {
            // 记录错误日志
            \Log::error('File rename error: ' . $e->getMessage(), [
                'origin_name' => $request->get('origin_name'),
                'new_name'    => $request->get('new_name'),
                'user_id'     => auth()->id(),
                'ip'          => $request->ip(),
            ]);

            return json_fail($e->getMessage());
        }
    }

    /**
     * 删除文件或文件夹
     * DELETE    /admin/file_manager/files  {"path":"/xx/yy", "files":["1.jpg", "2.png"]}
     * @throws Exception
     */
    public function destroyFiles(Request $request): JsonResponse
    {
        try {
            $requestData = json_decode($request->getContent(), true);

            // 验证请求数据
            if (! is_array($requestData)) {
                throw new \Exception(trans('admin/file_manager.invalid_request_data'));
            }

            $basePath = $requestData['path']  ?? '';
            $files    = $requestData['files'] ?? [];

            // 验证基础路径
            if (! is_string($basePath) || str_contains($basePath, '..')) {
                throw new \Exception(trans('admin/file_manager.invalid_path'));
            }

            // 验证文件列表
            if (! is_array($files)) {
                throw new \Exception(trans('admin/file_manager.invalid_files_parameter'));
            }

            // 验证每个文件名
            foreach ($files as $file) {
                if (! is_string($file) || ! $this->isValidFileName($file)) {
                    throw new \Exception(trans('admin/file_manager.invalid_filename'));
                }

                // 检查路径遍历
                if (str_contains($file, '..') || str_contains($file, '/') || str_contains($file, '\\')) {
                    throw new \Exception(trans('admin/file_manager.invalid_filename'));
                }
            }

            $this->fileManagerService->deleteFiles($basePath, $files);

            // 记录删除操作
            \Log::info('Files deleted', [
                'base_path' => $basePath,
                'files'     => $files,
                'user_id'   => auth()->id(),
                'ip'        => $request->ip(),
            ]);

            return json_success(trans('common.deleted_success'));
        } catch (Exception $e) {
            // 记录错误日志
            \Log::error('File deletion error: ' . $e->getMessage(), [
                'request_data' => $request->getContent(),
                'user_id'      => auth()->id(),
                'ip'           => $request->ip(),
            ]);

            return json_fail($e->getMessage());
        }
    }

    /**
     * 删除文件夹
     *
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     */
    public function destroyDirectories(Request $request): JsonResponse
    {
        try {
            $folderName = $request->get('name');
            $this->fileManagerService->deleteDirectoryOrFile($folderName);

            return json_success(trans('common.deleted_success'));
        } catch (Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    /**
     * 移动目录
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function moveDirectories(Request $request): JsonResponse
    {
        try {
            $sourcePath = $request->get('source_path');
            $destPath   = $request->get('dest_path');

            $this->fileManagerService->moveDirectory($sourcePath, $destPath);

            return json_success(trans('common.updated_success'));
        } catch (Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    /**
     * 移动多个图片文件
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function moveFiles(Request $request): JsonResponse
    {
        try {
            $images   = $request->get('images');
            $destPath = $request->get('dest_path');
            $this->fileManagerService->moveFiles($images, $destPath);

            return json_success(trans('common.updated_success'));
        } catch (Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    /**
     * 压缩文件夹下载ZIP
     *
     * @param Request $request
     */
    public function exportZip(Request $request)
    {
        try {
            // 验证请求参数
            $request->validate([
                'path' => 'required|string|max:255',
            ]);

            $imagePath = $request->get('path');

            // 基本的路径验证
            if (empty($imagePath) || str_contains($imagePath, '..')) {
                throw new \Exception(trans('admin/file_manager.invalid_path'));
            }

            $zipFile = $this->fileManagerService->zipFolder($imagePath);

            // 安全的文件名处理
            $safeFileName = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', basename($zipFile));

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $safeFileName . '"');
            header('Content-Length: ' . filesize($zipFile));
            readfile($zipFile);
            unlink($zipFile);

        } catch (Exception $e) {
            // 记录错误日志
            \Log::error('File export error: ' . $e->getMessage(), [
                'path'    => $request->get('path'),
                'user_id' => auth()->id(),
                'ip'      => $request->ip(),
            ]);

            abort(400, $e->getMessage());
        }
    }

    /**
     * 上传文件
     * POST      /admin/file_manager/upload
     *
     * @param UploadRequest $request
     * @return JsonResponse
     */
    public function uploadFiles(UploadRequest $request): JsonResponse
    {
        try {
            $file     = $request->file('file');
            $savePath = $request->get('path');

            $originName = $file->getClientOriginalName();
            $fileData   = $this->fileManagerService->uploadFile($file, $savePath, $originName);

            $uploadedFile = $this->fileManagerService->describeFile($fileData['path']);

            try {
                $files = $this->getFiles($request);
                if ($files instanceof JsonResponse) {
                    $files = $files->getData(true);
                }
                if (! is_array($files)) {
                    throw new \UnexpectedValueException('The refreshed media listing is not an array.');
                }
            } catch (Throwable $listingError) {
                Log::warning('Media uploaded but refreshed listing failed', [
                    'path'      => $uploadedFile['path'],
                    'exception' => $listingError,
                ]);
                $files = [
                    'images'      => [$uploadedFile],
                    'image_total' => 1,
                    'image_page'  => 1,
                ];
            }

            return response()->json([
                'name'          => $uploadedFile['name'],
                'url'           => $uploadedFile['origin_url'],
                'path'          => $uploadedFile['path'],
                'uploaded_file' => $uploadedFile,
                'files'         => $files,
            ]);
        } catch (Throwable $throwable) {
            Log::error('Media upload failed', [
                'directory' => $request->get('path'),
                'filename'  => $request->file('file')?->getClientOriginalName(),
                'user_id'   => auth()->id(),
                'exception' => $throwable,
            ]);

            return response()->json(['message' => trans('admin/file_manager.upload_store_failed')], 500);
        }
    }

    /**
     * 验证文件名是否安全
     *
     * @param string $fileName
     * @return bool
     */
    private function isValidFileName(string $fileName): bool
    {
        // 检查文件名长度
        if (strlen($fileName) > 255 || empty(trim($fileName))) {
            return false;
        }

        // 检查危险字符
        if (preg_match('#[<>:"|?*\x00-\x1f]#', $fileName)) {
            return false;
        }

        // 检查路径遍历模式
        if (str_contains($fileName, '..') || str_contains($fileName, '/') || str_contains($fileName, '\\')) {
            return false;
        }

        // 检查保留文件名 (Windows)
        $reservedNames  = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'];
        $nameWithoutExt = pathinfo($fileName, PATHINFO_FILENAME);
        if (in_array(strtoupper($nameWithoutExt), $reservedNames)) {
            return false;
        }

        // 如果是文件（有扩展名），验证扩展名
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        if (! empty($extension)) {
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'txt', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
            if (! in_array(strtolower($extension), $allowedExtensions)) {
                return false;
            }
        }

        return true;
    }

    protected function convertToBytes($sizeStr): int
    {
        $value = trim((string) $sizeStr);
        if (! preg_match('/^(\d+(?:\.\d+)?)\s*([kmg])?$/i', $value, $matches)) {
            return 0;
        }
        $bytes = (float) $matches[1];
        $unit  = strtolower($matches[2] ?? '');
        $bytes *= match ($unit) {
            'g'     => 1024 ** 3,
            'm'     => 1024 ** 2,
            'k'     => 1024,
            default => 1,
        };

        return (int) floor($bytes);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return round($bytes / (1024 ** 3), 1) . ' GB';
        }
        if ($bytes >= 1024 ** 2) {
            return round($bytes / (1024 ** 2), 1) . ' MB';
        }

        return max(1, (int) floor($bytes / 1024)) . ' KB';
    }
}
