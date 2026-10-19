<?php

namespace Beike\Console\Commands;

use Beike\Services\ShopifyMigrationValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ValidateShopifyMigration extends Command
{
    protected $signature = 'builder:validate-shopify {--scan=storage/app/builder/shopify-theme-manifest.json} {--manifest=storage/app/builder/shopify-template-migration-manifest.json} {--registry=storage/app/builder/shopify-puck-registry.json}';

    protected $description = 'Validate scanner, Shopify IR, and generated Puck registry consistency.';

    public function handle(ShopifyMigrationValidator $validator): int
    {
        $inputs  = ['scan' => $this->option('scan'), 'manifest' => $this->option('manifest'), 'registry' => $this->option('registry')];
        $decoded = [];
        foreach ($inputs as $name => $relative) {
            $path    = base_path((string) $relative);
            $content = is_file($path) ? json_decode((string) File::get($path), true) : null;
            if (! is_array($content)) {
                $this->error(ucfirst($name) . " artifact is missing or invalid: {$path}");

                return self::FAILURE;
            }
            $decoded[$name] = $content;
        }
        $result = $validator->validate($decoded['scan'], $decoded['manifest'], $decoded['registry']);
        $this->table(['Check', 'Count'], collect($result['checks'])->map(static fn (int $count, string $name): array => [$name, $count])->values()->all());
        if (! $result['valid']) {
            foreach ($result['errors'] as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $this->info('Shopify migration artifacts are consistent.');

        return self::SUCCESS;
    }
}
