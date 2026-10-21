<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class TenantMigrationIdentifierTest extends TestCase
{
    private const MYSQL_IDENTIFIER_LIMIT = 64;

    private const TENANT_TABLE_PREFIX = 'lt_aaaaaaaaaaaa_';

    public function test_generated_migration_identifiers_fit_prefixed_mysql_tenants(): void
    {
        $violations = [];

        foreach ($this->migrationFiles() as $path) {
            $source = (string) file_get_contents($path);
            preg_match_all(
                <<<'REGEX'
~Schema::(?:create|table)\(\s*['"](?<table>[^'"]+)['"]\s*,\s*function\s*\([^)]*\)\s*(?::\s*void\s*)?\{(?<body>.*?)\R\s*\}\);~s
REGEX,
                $source,
                $blocks,
                PREG_SET_ORDER,
            );

            foreach ($blocks as $block) {
                $table = $block['table'];
                $body  = $block['body'];

                preg_match_all(
                    <<<'REGEX'
~\$table->\w+\(\s*['"](?<column>[^'"]+)['"][^;]*?->(?<kind>unique|index)\(\s*\)~s
REGEX,
                    $body,
                    $columnIndexes,
                    PREG_SET_ORDER,
                );
                foreach ($columnIndexes as $index) {
                    $this->recordIfTooLong(
                        $violations,
                        $path,
                        $table,
                        [$index['column']],
                        $index['kind'],
                    );
                }

                preg_match_all(
                    <<<'REGEX'
~\$table->(?<kind>unique|index|foreign)\(\s*(?<columns>\[[^\]]+\]|['"][^'"]+['"])\s*\)~s
REGEX,
                    $body,
                    $tableIndexes,
                    PREG_SET_ORDER,
                );
                foreach ($tableIndexes as $index) {
                    preg_match_all("~['\"]([^'\"]+)['\"]~", $index['columns'], $columns);
                    $this->recordIfTooLong(
                        $violations,
                        $path,
                        $table,
                        $columns[1],
                        $index['kind'],
                    );
                }

                preg_match_all(
                    <<<'REGEX'
~\$table->foreignId\(\s*['"](?<column>[^'"]+)['"]\s*\)[^;]*?->constrained\(~s
REGEX,
                    $body,
                    $foreignIds,
                    PREG_SET_ORDER,
                );
                foreach ($foreignIds as $foreignId) {
                    $this->recordIfTooLong(
                        $violations,
                        $path,
                        $table,
                        [$foreignId['column']],
                        'foreign',
                    );
                }

                preg_match_all(
                    <<<'REGEX'
~\$table->foreign\([^,]+,\s*['"](?<name>[^'"]+)['"]\s*\)~s
REGEX,
                    $body,
                    $fixedForeignKeys,
                    PREG_SET_ORDER,
                );
                foreach ($fixedForeignKeys as $foreignKey) {
                    $violations[] = sprintf(
                        'fixed foreign-key symbol: %s (%s)',
                        str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1)),
                        $foreignKey['name'],
                    );
                }
            }

            preg_match_all(
                <<<'REGEX'
~\$table->(?<kind>index|unique)\(\s*\$[A-Za-z_][A-Za-z0-9_]*\s*\)~
REGEX,
                $source,
                $dynamicIndexes,
                PREG_SET_ORDER,
            );
            foreach ($dynamicIndexes as $dynamicIndex) {
                $violations[] = sprintf(
                    'unnamed dynamic %s: %s',
                    $dynamicIndex['kind'],
                    str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1)),
                );
            }
        }

        self::assertSame(
            [],
            $violations,
            'MySQL identifiers generated for prefixed tenants exceed 64 characters, or a fixed '
                . "foreign-key symbol would collide between tenants.\n"
                . 'Give long or dynamically declared indexes compact names and derive foreign-key symbols '
                . 'from the tenant prefix.',
        );
    }

    /** @return array<int, string> */
    private function migrationFiles(): array
    {
        $root  = dirname(__DIR__, 2);
        $files = [];

        foreach ([$root . '/database/migrations', $root . '/plugins'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                if ($directory === $root . '/plugins' && ! str_contains($path, '/Migrations/')) {
                    continue;
                }
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param array<int, string> $violations
     * @param array<int, string> $columns
     */
    private function recordIfTooLong(
        array &$violations,
        string $path,
        string $table,
        array $columns,
        string $kind,
    ): void {
        $identifier = strtolower(str_replace(
            ['-', '.'],
            '_',
            self::TENANT_TABLE_PREFIX . $table . '_' . implode('_', $columns) . '_' . $kind,
        ));
        if (strlen($identifier) <= self::MYSQL_IDENTIFIER_LIMIT) {
            return;
        }

        $violations[] = sprintf(
            '%d chars: %s (%s)',
            strlen($identifier),
            str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1)),
            $identifier,
        );
    }
}
