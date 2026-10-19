<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AdminFileManagerReliabilityTest extends TestCase
{
    public function test_upload_and_selection_contract_is_complete(): void
    {
        $root       = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($root . '/beike/Admin/Http/Controllers/FileManagerController.php');
        $service    = (string) file_get_contents($root . '/beike/Admin/Services/FileManagerService.php');
        $view       = (string) file_get_contents($root . '/resources/beike/admin/views/pages/file_manager/index.blade.php');
        $master     = (string) file_get_contents($root . '/resources/beike/admin/views/layouts/master.blade.php');
        $builder    = (string) file_get_contents($root . '/resources/beike/admin/js/builder/config.tsx');
        $common     = (string) file_get_contents($root . '/resources/beike/admin/js/common.js');
        $app        = (string) file_get_contents($root . '/resources/beike/admin/js/app.js');
        $styles     = (string) file_get_contents($root . '/resources/beike/admin/css/filemanager/app.scss');

        self::assertStringContainsString("'uploaded_file' => \$uploadedFile", $controller);
        self::assertStringContainsString("max(1, (int) \$request->get('page', 1))", $controller);
        self::assertStringContainsString('public function describeFile(string $path): array', $service);
        self::assertStringContainsString("'modified_at' => \$modifiedAt", $service);
        self::assertStringContainsString("'size'        => \$size", $service);
        self::assertStringContainsString("glob(\$currentBasePath . '/*') ?: []", $service);
        self::assertStringContainsString('window.LongosFileManager.nextMediaSelection', $view);
        self::assertStringContainsString('processUploadQueue()', $view);
        self::assertStringContainsString('this.uploadsInFlight < 3', $view);
        self::assertStringContainsString('newFile.controller = typeof AbortController', $view);
        self::assertStringContainsString('retryUpload(image)', $view);
        self::assertStringContainsString('requestId !== this.loadRequestId', $view);
        self::assertStringContainsString('handleMediaKeydown($event, index)', $view);
        self::assertStringContainsString('class="tree-action"', $view);
        self::assertStringContainsString(':multiple="Boolean(isMultiple)"', $view);
        self::assertStringContainsString('role="listbox"', $view);
        self::assertStringContainsString('class="filemanager-search"', $view);
        self::assertStringContainsString("'view-' + viewMode", $view);
        self::assertStringContainsString('class="filemanager-breadcrumbs"', $view);
        self::assertStringContainsString('class="filemanager-state filemanager-error-state"', $view);
        self::assertStringContainsString('this.loadError = error?.response?.data?.message', $view);
        self::assertStringContainsString("localStorage.setItem('file_manager_view_mode', mode)", $view);
        self::assertStringContainsString('openUploadPicker()', $view);
        self::assertStringContainsString(':aria-busy="String(loading)"', $view);
        self::assertStringNotContainsString('@if (!is_mobile())', $view);
        self::assertStringContainsString('this.applyFilePayload(res.files, uploadedFile)', $view);
        self::assertStringContainsString("typeof callback === 'function'", $view);
        self::assertStringContainsString('mediaSelectionFromMessage(event.data, "image")', $builder);
        self::assertStringContainsString('mediaSelectionFromMessage(event.data, "video")', $builder);
        self::assertStringContainsString("if (!base.pathname.endsWith('/')) base.pathname += '/'", $common);
        self::assertStringContainsString("adminUrl('file_manager')", $common);
        self::assertStringContainsString("adminUrl('login')", $app);
        self::assertStringContainsString("rtrim(\$admin_base_url, '/')", $view);
        self::assertStringContainsString("rtrim(\$admin_base_url, '/')", $master);
        self::assertStringContainsString("iframe.addEventListener('load', attachCallback)", $common);
        self::assertStringContainsString('--fm-primary: #4f46e5', $styles);
        self::assertStringContainsString('grid-template-columns: repeat(auto-fill, minmax(176px, 1fr))', $styles);
        self::assertStringContainsString('@media (max-width: 359.98px)', $styles);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        self::assertStringContainsString('min-height: 44px', $styles);
    }
}
