<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class BladeDirectiveCompatibilityTest extends TestCase
{
    #[DataProvider('bladeRoots')]
    public function test_views_do_not_use_ambiguous_inline_php_directives(string $directory): void
    {
        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $directory;
        if (! is_dir($root)) {
            self::markTestSkipped("Blade root does not exist: {$directory}");
        }

        $violations = [];
        $iterator   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/@php\s*\(/', $contents) === 1) {
                $violations[] = str_replace(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    /** @return array<string, array{string}> */
    public static function bladeRoots(): array
    {
        return [
            'application' => ['app'],
            'commerce'    => ['beike'],
            'plugins'     => ['plugins'],
            'resources'   => ['resources'],
            'themes'      => ['themes'],
        ];
    }
}
