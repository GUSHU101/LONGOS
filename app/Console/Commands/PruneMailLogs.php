<?php

namespace App\Console\Commands;

use Beike\Models\MailLog;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class PruneMailLogs extends Command
{
    protected $signature = 'longos:mail-logs:prune {--days= : Override this tenant\'s configured retention period}';

    protected $description = 'Fail abandoned attempts and prune old LONGOS mail audit content';

    public function handle(): int
    {
        if (! Schema::hasTable('mail_logs')) {
            $this->components->info('Mail audit storage is not installed; nothing to prune.');

            return self::SUCCESS;
        }

        $configured = $this->option('days');
        $days       = $configured !== null
            ? (int) $configured
            : (int) system_setting('base.mail_log_retention_days', 30);
        $days   = max(1, min(365, $days));
        $cutoff = now()->subDays($days);

        $staleAttempts = MailLog::query()
            ->where('status', MailLog::STATUS_PENDING)
            ->where(function (Builder $query): void {
                $query->where('last_attempt_at', '<', now()->subDay())
                    ->orWhere(function (Builder $query): void {
                        $query->whereNull('last_attempt_at')->where('created_at', '<', now()->subDay());
                    });
            })
            ->update([
                'status'        => MailLog::STATUS_FAILED,
                'error_message' => '邮件发送进程中断，未收到传输层确认。',
                'updated_at'    => now(),
            ]);

        $deleted = 0;
        $this->expired(MailLog::query(), $cutoff)
            ->select('id')
            ->chunkById(500, function ($logs) use ($cutoff, &$deleted): void {
                $ids = $logs->pluck('id');
                if ($ids->isNotEmpty()) {
                    $deleted += $this->expired(MailLog::query()->whereKey($ids), $cutoff)->delete();
                }
            });

        $this->components->info("Marked {$staleAttempts} stale attempt(s) failed and pruned {$deleted} mail log(s) older than {$days} day(s).");

        return self::SUCCESS;
    }

    private function expired(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query->where('status', '!=', MailLog::STATUS_PENDING)
            ->where('created_at', '<', $cutoff);
    }
}
