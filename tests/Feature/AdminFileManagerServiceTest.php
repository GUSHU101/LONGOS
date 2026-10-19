<?php

namespace Tests\Feature;

use Beike\Admin\Services\FileManagerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class AdminFileManagerServiceTest extends TestCase
{
    private string $testRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $this->testRoot = storage_path('framework/testing/file-manager-' . Str::uuid());
        File::ensureDirectoryExists($this->testRoot . '/public/image/catalog');
        $app->instance('path.public', $this->testRoot . '/public');
        config([
            'saas.isolate_files'        => true,
            'filesystems.disks.catalog' => [
                'driver' => 'local',
                'root'   => $this->testRoot . '/public/image/catalog',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->testRoot) && str_contains(str_replace('\\', '/', $this->testRoot), '/framework/testing/file-manager-')) {
            File::deleteDirectory($this->testRoot);
        }
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_uploaded_file_can_immediately_be_listed_and_selected(): void
    {
        $source = $this->testRoot . '/source.gif';
        file_put_contents($source, base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true));
        $upload  = new UploadedFile($source, 'hero.gif', 'image/gif', null, true);
        $service = new FileManagerService;

        $stored     = $service->uploadFile($upload, '/', 'hero.gif');
        $descriptor = $service->describeFile($stored['path']);
        $files      = $service->getFiles('/', '', 'created', 'desc', 1, 20);
        $search     = $service->getFiles('/', 'HERO', 'name', 'asc', 1, 20, 'image');

        self::assertSame('image/catalog/hero.gif', $stored['path']);
        self::assertSame('/image/catalog/hero.gif', $descriptor['path']);
        self::assertSame('image/gif', $descriptor['mime']);
        self::assertSame('gif', $descriptor['extension']);
        self::assertGreaterThan(0, $descriptor['size']);
        self::assertIsInt($descriptor['modified_at']);
        self::assertFalse($descriptor['selected']);
        self::assertSame('/image/catalog/hero.gif', $files['images'][0]['path']);
        self::assertSame('/image/catalog/hero.gif', $search['images'][0]['path']);
        self::assertSame(1, $files['image_total']);
        self::assertSame(1, $files['image_page']);

        $service->createDirectory('/destination');
        file_put_contents($this->testRoot . '/public/image/catalog/destination/hero.gif', 'existing');
        $service->moveFiles(['/image/catalog/hero.gif'], '/destination');
        $moved = $service->getFiles('/destination', '', 'name', 'asc', 1, 20, 'image');

        self::assertSame(2, $moved['image_total']);
        self::assertSame('existing', file_get_contents($this->testRoot . '/public/image/catalog/destination/hero.gif'));
        self::assertFileDoesNotExist($this->testRoot . '/public/image/catalog/hero.gif');
    }
}
