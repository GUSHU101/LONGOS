<?php

namespace App\Console\Commands;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Plugin\LongosLiveChat\Models\Conversation;

class PruneLiveChat extends Command
{
    protected $signature = 'longos:live-chat:prune {--days= : Override the configured retention period}';

    protected $description = 'Delete inactive LONGOS live-chat conversations after their retention period';

    public function handle(): int
    {
        if (! Schema::hasTable('longos_live_chat_conversations')
            || ! Schema::hasColumn('longos_live_chat_conversations', 'closed_at')) {
            $this->components->info('LONGOS live-chat storage is not installed; nothing to prune.');

            return self::SUCCESS;
        }

        $configured = $this->option('days');
        $days       = $configured !== null
            ? (int) $configured
            : (int) plugin_setting('longos_live_chat.retention_days', 1);
        $days    = max(1, min(3650, $days));
        $cutoff  = now()->subDays($days);
        $deleted = 0;

        $this->expired(Conversation::query(), $cutoff)
            ->select('id')
            ->chunkById(500, function ($conversations) use ($cutoff, &$deleted): void {
                $ids = $conversations->pluck('id');
                if ($ids->isEmpty()) {
                    return;
                }

                // Recheck the activity cutoff in the DELETE statement. A new
                // message received between selection and deletion must win.
                $deleted += $this->expired(
                    Conversation::query()->whereKey($ids),
                    $cutoff,
                )->delete();
            });

        $this->components->info("Pruned {$deleted} inactive live-chat conversation(s) older than {$days} day(s).");

        return self::SUCCESS;
    }

    private function expired(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query->where(function (Builder $query) use ($cutoff): void {
            $query->where('last_message_at', '<', $cutoff)
                ->orWhere(function (Builder $query) use ($cutoff): void {
                    $query->whereNull('last_message_at')
                        ->where('created_at', '<', $cutoff);
                });
        });
    }
}
