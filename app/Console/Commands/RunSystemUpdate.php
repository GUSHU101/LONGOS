<?php

namespace App\Console\Commands;

use App\Updates\UpdateManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RunSystemUpdate extends Command
{
    protected $signature = 'longos:update:run {job : Queued LONGOS update UUID}';

    protected $description = 'Run one already-authorized LONGOS application update';

    public function handle(UpdateManager $updates): int
    {
        $jobId = (string) $this->argument('job');
        if (! Str::isUuid($jobId)) {
            $this->components->error('Invalid LONGOS update job UUID.');

            return self::FAILURE;
        }

        $updates->run($jobId);

        return self::SUCCESS;
    }
}
