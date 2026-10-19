<?php

namespace Beike\Console\Commands;

use Beike\Services\ShopifyMigrationAnalyzer;
use Beike\Services\ShopifyMigrationReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AnalyzeShopifyMigration extends Command
{
    protected $signature = 'builder:analyze-shopify {--scan=storage/app/builder/shopify-theme-manifest.json : Scanner manifest path} {--output=storage/app/builder/shopify-template-migration-manifest.json : IR manifest path} {--report=docs/SHOPIFY-TEMPLATE-MIGRATION-REPORT.md : Markdown report path}';

    protected $description = 'Build Shopify component IR and an evidence-based migration report from a scan manifest.';

    public function handle(ShopifyMigrationAnalyzer $analyzer, ShopifyMigrationReport $reporter): int
    {
        $scanPath = base_path((string) $this->option('scan'));
        if (! is_file($scanPath)) {
            $this->error("Scanner manifest not found: {$scanPath}");

            return self::FAILURE;
        }
        $scan = json_decode((string) File::get($scanPath), true);
        if (! is_array($scan)) {
            $this->error('Scanner manifest JSON is invalid.');

            return self::FAILURE;
        }

        try {
            $manifest = $analyzer->analyze($scan);
            $output   = base_path((string) $this->option('output'));
            $report   = base_path((string) $this->option('report'));
            File::ensureDirectoryExists(dirname($output));
            File::ensureDirectoryExists(dirname($report));
            File::put($output, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            File::put($report, $reporter->render($manifest));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $status = $manifest['summary']['migration_status'];
        $this->table(['Metric', 'Value'], [
            ['Components analyzed', count($manifest['components'])],
            ['Analyzed', $status['ANALYZED'] ?? 0],
            ['Manual review', $status['MANUAL_REVIEW'] ?? 0],
            ['IR manifest', $output],
            ['Markdown report', $report],
        ]);

        return self::SUCCESS;
    }
}
