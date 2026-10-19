<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class SeederRetrySafetyTest extends TestCase
{
    public function test_default_seeders_never_truncate_tenant_tables(): void
    {
        $root     = dirname(__DIR__, 2) . '/database/seeders';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            self::assertStringNotContainsString('->truncate()', $source, $file->getPathname());
            self::assertDoesNotMatchRegularExpression('/\bTRUNCATE\s+TABLE\b/i', $source, $file->getPathname());
        }
    }

    public function test_interrupted_seed_retries_use_non_destructive_inserts(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            'AttributesSeeder.php',
            'BrandsSeeder.php',
            'CategoriesSeeder.php',
            'CurrenciesSeeder.php',
            'CustomerGroupsSeeder.php',
            'LanguagesSeeder.php',
            'PageCategoriesSeeder.php',
            'PagesSeeder.php',
            'PluginsSeeder.php',
            'RmaReasonsSeeder.php',
            'SettingsSeeder.php',
        ] as $file) {
            $source = (string) file_get_contents($root . '/database/seeders/' . $file);
            self::assertStringContainsString('firstOrCreate(', $source, $file);
        }

        $databaseSeeder = (string) file_get_contents($root . '/database/seeders/DatabaseSeeder.php');
        self::assertStringContainsString('DatabaseReadiness::isSeeded', $databaseSeeder);
    }
}
