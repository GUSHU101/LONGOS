<?php

namespace App\Console\Commands;

use Beike\Services\QueueService;
use Illuminate\Console\Command;

class QueueWorkerHealth extends Command
{
    protected $signature = 'longos:queue-health
        {--queue=default : Queue name written by the worker heartbeat}
        {--max-age=30 : Maximum idle heartbeat age in seconds}';

    protected $description = 'Fail unless a LONGOS queue worker has a recent shared-volume heartbeat';

    public function handle(): int
    {
        $queue = trim((string) $this->option('queue'));
        $age   = filter_var($this->option('max-age'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 5, 'max_range' => 300],
        ]);

        if (preg_match('/^[A-Za-z0-9_.:-]+$/', $queue) !== 1 || $age === false) {
            $this->components->error('Invalid queue health-check arguments.');

            return self::INVALID;
        }

        if (! QueueService::hasRecentWorkerHeartbeat($queue, (int) $age)) {
            $this->components->error("No recent heartbeat was found for queue [{$queue}].");

            return self::FAILURE;
        }

        $this->components->info("Queue worker [{$queue}] is responsive.");

        return self::SUCCESS;
    }
}
