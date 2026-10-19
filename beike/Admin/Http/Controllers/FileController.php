<?php

namespace Beike\Admin\Http\Controllers;

use Beike\Admin\Services\RemoteImageImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FileController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // 验证请求参数
        $request->validate([
            'file' => 'required|file|max:51200',
            'type' => 'required|string|alpha_dash|max:255',
        ]);

        $file = $request->file('file');
        $type = $request->get('type');

        // 验证文件类型和安全性
        $this->validateFileUpload($file, $type);

        // 清理类型参数，防止路径遍历
        $type = $this->sanitizeType($type);

        $path = $file->store($type, 'upload');
        if (! is_string($path) || $path === '') {
            throw new RuntimeException(trans('admin/file_manager.upload_store_failed'));
        }
        $publicPath = tenant_upload_prefix() . '/' . $path;

        $data = [
            'url'   => asset($publicPath),
            'value' => $publicPath,
        ];

        $data = hook_filter('file.store.data', $data);

        // 记录上传日志
        \Log::info('File uploaded', [
            'original_name' => $file->getClientOriginalName(),
            'path'          => $path,
            'type'          => $type,
            'user_id'       => auth()->id(),
            'ip'            => $request->ip(),
        ]);

        return json_success(trans('shop/file.uploaded_success'), $data);
    }

    /**
     * 验证文件上传的安全性
     */
    private function validateFileUpload(UploadedFile $file, string $type): void
    {
        if (! $file->isValid()) {
            throw new RuntimeException(trans('admin/file_manager.upload_store_failed'));
        }

        // 允许的文件扩展名
        $allowedExtensions = [
            'image'       => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'document'    => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'],
            'video'       => ['mp4', 'avi', 'mov'],
            'plugin_file' => ['zip'],
        ];

        // 允许的MIME类型
        $allowedMimeTypes = [
            'image'       => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'document'    => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ],
            'video'       => ['video/mp4', 'video/quicktime', 'video/x-msvideo'],
            'plugin_file' => ['application/zip', 'application/x-zip-compressed'],
        ];

        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType  = $file->getMimeType();

        // 检查类型是否被允许
        if (! isset($allowedExtensions[$type])) {
            throw new \Exception(trans('admin/file_manager.invalid_upload_type'));
        }

        // 检查文件扩展名
        if (! in_array($extension, $allowedExtensions[$type])) {
            throw new \Exception(trans('admin/file_manager.upload_type_fail'));
        }

        // 检查MIME类型
        if (isset($allowedMimeTypes[$type]) && ! in_array($mimeType, $allowedMimeTypes[$type])) {
            throw new \Exception(trans('admin/file_manager.upload_type_fail'));
        }

        // 检查文件名安全性
        $originalName = $file->getClientOriginalName();
        if (! $this->isValidFileName($originalName)) {
            throw new \Exception(trans('admin/file_manager.invalid_filename'));
        }

        if ($type === 'image') {
            $dimensions = @getimagesize($file->getRealPath());
            $width      = is_array($dimensions) ? (int) ($dimensions[0] ?? 0) : 0;
            $height     = is_array($dimensions) ? (int) ($dimensions[1] ?? 0) : 0;
            if ($width < 1 || $height < 1 || $width > 16000 || $height > 16000 || ($width * $height) > 80000000) {
                throw new RuntimeException(trans('admin/file_manager.image_dimensions_fail'));
            }
        }
    }

    /**
     * 清理类型参数
     */
    private function sanitizeType(string $type): string
    {
        // 移除危险字符
        $type = preg_replace('/[^a-zA-Z0-9_-]/', '', $type);

        // 防止路径遍历
        $type = str_replace(['..', '/', '\\'], '', $type);

        return $type;
    }

    /**
     * 验证文件名是否安全
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

        return true;
    }

    public function catchImages(Request $request, RemoteImageImporter $importer)
    {
        $validated = $request->validate([
            'source'   => 'required|array|min:1|max:20',
            'source.*' => 'required|string|max:2048',
            'type'     => 'required|in:image',
        ]);

        return json_success(
            trans('shop/file.uploaded_success'),
            $importer->import($validated['source'], $validated['type']),
        );
    }
}
