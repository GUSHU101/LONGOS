<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TenantDeferredSafetyTest extends TestCase
{
    public function test_plugins_do_not_register_raw_application_terminating_callbacks(): void
    {
        $plugins = dirname(__DIR__, 2) . '/plugins';
        $files   = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($plugins, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            self::assertStringNotContainsString(
                'app()->terminating(',
                (string) file_get_contents($file->getPathname()),
                'Plugin callbacks must use TenantDeferred::terminating() so their tenant database is restored.',
            );
        }
    }
}
