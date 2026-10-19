<?php

namespace Beike\Console\Commands;

use Beike\Services\LocationIntegrityService;
use Illuminate\Console\Command;
use Throwable;

class AuditLocations extends Command
{
    protected $signature = 'longos:locations:audit {--fix : Apply deterministic, non-destructive repairs}';

    protected $aliases = ['beikeshop:locations:audit'];

    protected $description = 'Audit country/region and province data integrity';

    public function handle(LocationIntegrityService $integrity): int
    {
        try {
            if ($this->option('fix')) {
                $repaired = $integrity->repair();
                $this->info('Applied repairs: ' . json_encode($repaired, JSON_UNESCAPED_UNICODE));
            }

            $report = $integrity->audit();
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('Unable to audit the location catalog. Verify the database connection and migration status.');

            return self::FAILURE;
        }

        $this->info("Checked {$report['checked']['countries']} countries and {$report['checked']['zones']} zones.");

        if ($report['issues'] === []) {
            $this->info('Location catalog is consistent.');

            return self::SUCCESS;
        }

        $this->table(['Type', 'ID', 'Detail'], array_map(fn ($issue) => [$issue['type'], $issue['id'], $issue['message']], $report['issues']));

        return self::FAILURE;
    }
}
