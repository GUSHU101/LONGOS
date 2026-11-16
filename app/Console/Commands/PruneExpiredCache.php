<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneExpiredCache extends Command
{
    protected $signature = 'longos:cache:prune-expired
        {--batch=5000 : Rows selected per delete batch}
        {--passes=20 : Maximum batches per table}';

    protected $description = 'Prune expired database cache and lock rows in bounded batches';

    public function handle(): int
    {
        $store = (string) config('cache.default');
        if ((string) config("cache.stores.{$store}.driver") !== 'database') {
            $this->components->info('The active cache store is not database-backed; nothing to prune.');

            return self::SUCCESS;
        }

        $connection     = config("cache.stores.{$store}.connection");
        $lockConnection = config("cache.stores.{$store}.lock_connection") ?: $connection;
        $batch          = max(100, min(20000, (int) $this->option('batch')));
        $passes         = max(1, min(100, (int) $this->option('passes')));
        $targets        = [
            [$connection, (string) config("cache.stores.{$store}.table")],
            [$lockConnection, (string) config("cache.stores.{$store}.lock_table")],
        ];
        $seen    = [];
        $deleted = 0;

        foreach ($targets as [$targetConnection, $table]) {
            $identity = (string) $targetConnection . '|' . $table;
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;

            if (! preg_match('/^[a-zA-Z0-9_]+$/D', $table)
                || ! Schema::connection($targetConnection)->hasTable($table)) {
                continue;
            }

            for ($pass = 0; $pass < $passes; $pass++) {
                $keys = DB::connection($targetConnection)
                    ->table($table)
                    ->where('expiration', '<=', time())
                    ->orderBy('expiration')
                    ->limit($batch)
                    ->pluck('key');
                if ($keys->isEmpty()) {
                    break;
                }

                // Recheck expiry in the DELETE so a concurrently renewed
                // cache entry or lock always wins over the pruning pass.
                $removed = DB::connection($targetConnection)
                    ->table($table)
                    ->whereIn('key', $keys->all())
                    ->where('expiration', '<=', time())
                    ->delete();
                $deleted += $removed;
                if ($keys->count() < $batch) {
                    break;
                }
            }
        }

        $this->components->info("Pruned {$deleted} expired cache row(s).");

        return self::SUCCESS;
    }
}
