<?php

namespace Beike\Console\Commands;

use Beike\Admin\Services\LocationManagementService;
use Illuminate\Console\Command;
use Throwable;

class SyncLocations extends Command
{
    protected $signature = 'longos:locations:sync
        {--countries-only : Synchronize countries without provinces}
        {--zones-only : Synchronize provinces after ensuring country mappings exist}';

    protected $description = 'Non-destructively synchronize the bundled LONGOS country and province catalog';

    public function handle(LocationManagementService $locations): int
    {
        if ($this->option('countries-only') && $this->option('zones-only')) {
            $this->components->error('Choose at most one of --countries-only and --zones-only.');

            return self::INVALID;
        }

        try {
            $countries = null;
            $zones     = null;
            if (! $this->option('zones-only')) {
                $countries = $locations->syncCountries();
            }
            if (! $this->option('countries-only')) {
                // Province mappings use ISO country codes. Ensure a missing
                // country never causes a valid bundled province to disappear.
                $countries ??= $locations->syncCountries();
                $zones = $locations->syncZones();
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('Unable to synchronize the location catalog. Existing records were preserved.');

            return self::FAILURE;
        }

        if ($countries !== null) {
            $this->components->info('Countries: ' . json_encode($countries, JSON_UNESCAPED_UNICODE));
        }
        if ($zones !== null) {
            $this->components->info('Provinces: ' . json_encode($zones, JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
