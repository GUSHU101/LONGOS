<?php

namespace Beike\Console\Commands;

use Beike\Services\ShopifyThemeScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InspectShopifyTheme extends Command
{
    protected $signature = 'builder:inspect-shopify {--source= : Shopify theme directory} {--output= : JSON report path relative to the project root}';

    protected $description = 'Recursively inventory a Shopify theme and build its Liquid dependency manifest.';

    public function handle(ShopifyThemeScanner $scanner): int
    {
        try {
            $report = $scanner->scan($this->option('source') ?: null);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->table(['Metric', 'Value'], [
            ['Source', $report['source']],
            ['Files discovered', $report['summary']['discovered']],
            ['Component units', count($report['component_units'])],
            ['Warnings', $report['summary']['warnings']],
        ]);
        $output = $this->option('output');
        if ($output) {
            $path = base_path(ltrim((string) $output, '/\\'));
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->info("Report written to {$path}");
        }

        return self::SUCCESS;
    }
}
