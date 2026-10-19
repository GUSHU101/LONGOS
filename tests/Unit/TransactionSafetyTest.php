<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TransactionSafetyTest extends TestCase
{
    public function test_application_code_does_not_manually_balance_database_transactions(): void
    {
        $root  = dirname(__DIR__, 2);
        $paths = [$root . '/app', $root . '/beike', $root . '/plugins'];

        foreach ($paths as $path) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());
                self::assertStringNotContainsString('DB::beginTransaction()', $source, $file->getPathname());
                self::assertStringNotContainsString('DB::commit()', $source, $file->getPathname());
                self::assertStringNotContainsString('DB::rollBack()', $source, $file->getPathname());
            }
        }
    }
}
