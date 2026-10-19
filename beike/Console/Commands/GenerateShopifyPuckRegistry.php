<?php

namespace Beike\Console\Commands;

use Beike\Services\ShopifyPuckRegistryGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateShopifyPuckRegistry extends Command
{
    protected $signature = 'builder:generate-puck-registry {--manifest=storage/app/builder/shopify-template-migration-manifest.json} {--output=storage/app/builder/shopify-puck-registry.json}';

    protected $description = 'Generate an auditable Puck registry artifact from Shopify migration IR.';

    public function handle(ShopifyPuckRegistryGenerator $generator): int
    {
        $manifestPath = base_path($this->option('manifest'));
        if (! is_file($manifestPath)) {
            $this->error("Manifest not found: {$manifestPath}");

            return self::FAILURE;
        }
        $manifest = json_decode((string) File::get($manifestPath), true);
        if (! is_array($manifest)) {
            $this->error('Manifest JSON is invalid.');

            return self::FAILURE;
        }
        $registry = $generator->generate($manifest);
        $output   = base_path($this->option('output'));
        File::ensureDirectoryExists(dirname($output));
        $encoded = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (is_file($output) && hash_equals((string) File::get($output), $encoded)) {
            $this->info('No Shopify source changes detected; Puck registry is current.');

            return self::SUCCESS;
        }
        File::put($output, $encoded);
        $this->info('Generated ' . count($registry['components']) . " component definitions at {$output}");

        return self::SUCCESS;
    }
}
