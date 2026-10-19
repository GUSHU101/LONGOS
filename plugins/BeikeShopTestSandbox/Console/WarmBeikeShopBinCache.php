<?php

namespace Plugin\BeikeShopTestSandbox\Console;

use Illuminate\Console\Command;
use Plugin\BeikeShopTestSandbox\Services\BinSearchLookupService;
use Plugin\BeikeShopTestSandbox\Support\TestCardCatalog;

class WarmBeikeShopBinCache extends Command
{
    protected $signature = 'beikeshop-test:bin-warm
                            {--bin=* : Optional 6-8 digit BINs. Defaults to config warm_bins.}';

    protected $description = 'Warm the LONGOS TEST BIN cache through the BinSearchLookup batch API.';

    public function handle(BinSearchLookupService $service): int
    {
        $bins = $this->option('bin') ?: config(
            'beikeshop_test.bin_lookup.warm_bins',
            TestCardCatalog::providerWarmBins()
        );
        $bins = array_values(array_unique(array_map(
            static fn (string $bin): string => (string) preg_replace('/\D+/', '', $bin),
            $bins
        )));

        $invalidBins = array_values(array_filter(
            $bins,
            static fn (string $bin): bool => ! TestCardCatalog::allowsProviderBin($bin)
        ));

        if ($invalidBins !== []) {
            $this->error('Whitelist mode only permits BINs derived from configured synthetic TEST cards.');

            return self::INVALID;
        }

        $this->info('Warming ' . count($bins) . ' BIN(s)...');

        $result = $service->batchWarm($bins, ['orderId' => null]);

        $this->line(json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return ($result['status'] ?? null) === 'OK'
            ? self::SUCCESS
            : self::FAILURE;
    }
}
