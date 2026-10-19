<?php

namespace Tests\Feature;

use App\Updates\ReleasePackage;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class ReleasePackageSecurityTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $this->temporary = sys_get_temp_dir() . '/longos-release-test-' . bin2hex(random_bytes(8));
        mkdir($this->temporary, 0700, true);
        config([
            'longos.updater.maximum_files' => 1000,
            'longos.updater.maximum_bytes' => 10485760,
            'beike.version'                => '2.0.0.35',
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporary);
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_it_stages_only_a_complete_hash_verified_release(): void
    {
        $archive = $this->createArchive();
        $staged  = (new ReleasePackage)->stage($archive, $this->temporary . '/stage', '2.0.0.36');

        self::assertSame('2.0.0.36', $staged['manifest']['version']);
        self::assertSame('safe', file_get_contents($this->temporary . '/stage/app/Safe.php'));
        self::assertFileDoesNotExist($this->temporary . '/stage/longos-release.json.tmp');
    }

    public function test_it_rejects_a_protected_environment_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('受保护路径');

        $archive = $this->createArchive(['.env' => 'APP_KEY=stolen']);
        (new ReleasePackage)->stage($archive, $this->temporary . '/stage', '2.0.0.36');
    }

    public function test_it_rejects_host_deployment_controls(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('受保护路径');

        $archive = $this->createArchive(['scripts/host-edge.sh' => '# unsafe']);
        (new ReleasePackage)->stage($archive, $this->temporary . '/stage', '2.0.0.36');
    }

    public function test_it_rejects_a_file_whose_content_does_not_match_the_manifest(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('发布清单校验失败');

        $archive = $this->createArchive([], ['app/Safe.php' => str_repeat('0', 64)]);
        (new ReleasePackage)->stage($archive, $this->temporary . '/stage', '2.0.0.36');
    }

    public function test_it_rejects_case_insensitive_path_collisions(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('大小写冲突');

        $archive = $this->createArchive(['App/Safe.php' => 'collision']);
        (new ReleasePackage)->stage($archive, $this->temporary . '/stage', '2.0.0.36');
    }

    public function test_it_rejects_non_portable_or_ambiguous_filesystem_paths(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('不兼容安全文件系统');

        $archive = $this->createArchive(['app/CON.php' => 'reserved']);
        (new ReleasePackage)->stage($archive, $this->temporary . '/stage', '2.0.0.36');
    }

    public function test_a_tampered_rollback_ledger_cannot_target_protected_files(): void
    {
        $backup = $this->temporary . '/backup';
        mkdir($backup, 0700, true);
        file_put_contents($backup . '/ledger.json', json_encode([
            'replaced' => ['.env'],
            'created'  => [],
            'deleted'  => [],
        ], JSON_THROW_ON_ERROR));

        self::assertNull((new ReleasePackage)->readLedger($backup));
    }

    public function test_a_rollback_ledger_cannot_reuse_one_path_across_operations(): void
    {
        $backup = $this->temporary . '/overlap-backup';
        mkdir($backup, 0700, true);
        file_put_contents($backup . '/ledger.json', json_encode([
            'replaced' => ['app/Safe.php'],
            'created'  => ['App/Safe.php'],
            'deleted'  => [],
        ], JSON_THROW_ON_ERROR));

        self::assertNull((new ReleasePackage)->readLedger($backup));
    }

    public function test_rollback_refuses_a_corrupted_code_backup(): void
    {
        $relative = 'tests/.longos-rollback-' . bin2hex(random_bytes(6)) . '.txt';
        $live     = dirname(__DIR__, 2) . '/' . $relative;
        $backup   = $this->temporary . '/integrity-backup';
        $copy     = $backup . '/files/' . $relative;
        mkdir(dirname($copy), 0700, true);
        file_put_contents($live, 'updated');
        file_put_contents($copy, 'corrupted');
        file_put_contents($backup . '/ledger.json', json_encode([
            'schema'   => 2,
            'replaced' => [$relative],
            'created'  => [],
            'deleted'  => [],
            'backups'  => [$relative => hash('sha256', 'original')],
            'modes'    => [$relative => 0644],
        ], JSON_THROW_ON_ERROR));

        try {
            $ledger = (new ReleasePackage)->readLedger($backup);
            self::assertIsArray($ledger);
            (new ReleasePackage)->rollback($backup, $ledger);
            self::fail('A corrupted rollback backup must never replace a live file.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('完整性校验失败', $exception->getMessage());
            self::assertSame('updated', file_get_contents($live));
        } finally {
            @unlink($live);
        }
    }

    public function test_post_install_verification_detects_a_corrupted_live_file(): void
    {
        $relative = 'tests/.longos-installed-' . bin2hex(random_bytes(6)) . '.txt';
        $live     = dirname(__DIR__, 2) . '/' . $relative;
        file_put_contents($live, 'verified-release');

        try {
            $package = new ReleasePackage;
            $package->verifyInstalled(['files' => [
                $relative => hash('sha256', 'verified-release'),
            ]]);

            file_put_contents($live, 'corrupted-after-write');
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('哈希不一致');
            $package->verifyInstalled(['files' => [
                $relative => hash('sha256', 'verified-release'),
            ]]);
        } finally {
            @unlink($live);
        }
    }

    public function test_delete_manifest_cannot_remove_protected_or_case_colliding_paths(): void
    {
        try {
            (new ReleasePackage)->stage(
                $this->createArchive([], [], ['.env']),
                $this->temporary . '/protected-delete',
                '2.0.0.36',
            );
            self::fail('A protected deletion should be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('删除路径无效', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('删除路径无效');
        (new ReleasePackage)->stage(
            $this->createArchive([], [], ['App/Safe.php']),
            $this->temporary . '/case-delete',
            '2.0.0.36',
        );
    }

    /** @param array<string, string> $extra @param array<string, string> $hashOverrides @param array<int, string> $delete */
    private function createArchive(array $extra = [], array $hashOverrides = [], array $delete = []): string
    {
        $files = array_merge([
            'composer.json'                 => '{}',
            'composer.lock'                 => '{}',
            'beike/Config/beike.php'        => '<?php return [];',
            'bootstrap/app.php'             => '<?php return [];',
            'public/index.php'              => '<?php',
            'vendor/autoload.php'           => '<?php',
            'vendor/composer/installed.php' => '<?php return [];',
            'app/Safe.php'                  => 'safe',
        ], $extra);
        $hashes = [];
        foreach ($files as $path => $contents) {
            $hashes[$path] = $hashOverrides[$path] ?? hash('sha256', $contents);
        }

        $manifest = [
            'schema'   => 1,
            'product'  => 'LONGOS',
            'version'  => '2.0.0.36',
            'requires' => ['php' => '8.2.0', 'longos' => '2.0.0.35'],
            'files'    => $hashes,
            'delete'   => $delete,
        ];
        $archive = $this->temporary . '/release-' . bin2hex(random_bytes(4)) . '.zip';
        $zip     = new ZipArchive;
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL));
        foreach ($files as $path => $contents) {
            self::assertTrue($zip->addFromString($path, $contents));
        }
        self::assertTrue($zip->addFromString(
            'longos-release.json',
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ));
        self::assertTrue($zip->close());

        return $archive;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
