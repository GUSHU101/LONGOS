<?php

namespace Beike\Console\Commands;

use Beike\Services\ShopifyMigrationAnalyzer;
use Beike\Services\ShopifyMigrationReport;
use Beike\Services\ShopifyPuckRegistryGenerator;
use Beike\Services\ShopifyThemeScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/** Runs the complete Shopify pipeline atomically, with a non-writing dry-run. */
class MigrateShopifyTheme extends Command
{
    protected $signature = 'builder:migrate-shopify {--source= : Shopify theme source directory} {--dry-run : Analyze only; do not write generated artifacts}';

    protected $description = 'Scan, analyze, and generate the Shopify Puck registry in one repeatable command.';

    public function handle(ShopifyThemeScanner $scanner, ShopifyMigrationAnalyzer $analyzer, ShopifyPuckRegistryGenerator $generator, ShopifyMigrationReport $reporter): int
    {
        try {
            $scan     = $scanner->scan($this->option('source') ?: null);
            $manifest = $analyzer->analyze($scan);
            $registry = $generator->generate($manifest);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $status = $manifest['summary']['migration_status'];
        $this->table(['Metric', 'Value'], [
            ['Files discovered', $manifest['summary']['discovered']],
            ['Component units', count($manifest['components'])],
            ['Analyzed', $status['ANALYZED'] ?? 0],
            ['Manual review', $status['MANUAL_REVIEW'] ?? 0],
            ['Generated registry definitions', count($registry['components'])],
        ]);
        if ($this->option('dry-run')) {
            $this->info('Dry run completed. No migration artifacts were written.');

            return self::SUCCESS;
        }

        try {
            $artifacts = [
                storage_path('app/builder/shopify-theme-manifest.json')              => json_encode($scan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                storage_path('app/builder/shopify-template-migration-manifest.json') => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                storage_path('app/builder/shopify-puck-registry.json')               => json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                base_path('docs/SHOPIFY-TEMPLATE-MIGRATION-REPORT.md')               => $reporter->render($manifest),
            ];
            foreach ($artifacts as $path => $contents) {
                File::ensureDirectoryExists(dirname($path));
                if (! is_file($path) || ! hash_equals((string) File::get($path), $contents)) {
                    File::put($path, $contents);
                }
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Shopify migration artifacts are current.');

        return self::SUCCESS;
    }
}
