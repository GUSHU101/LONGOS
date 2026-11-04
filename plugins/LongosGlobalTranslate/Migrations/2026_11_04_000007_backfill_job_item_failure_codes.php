<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Older task rows only recorded their terminal state on the durable usage
 * ledger.  Preserve that evidence on the corresponding work item so the
 * administration UI can explain an old partial failure without exposing
 * provider responses, source text, credentials, or request payloads.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lgt_job_items')
            || ! Schema::hasTable('lgt_attempts')
            || ! Schema::hasColumn('lgt_job_items', 'failure_code')) {
            return;
        }

        DB::table('lgt_job_items')
            ->where('status', 'failed')
            ->whereNull('failure_code')
            ->orderBy('id')
            ->chunkById(200, function ($items): void {
                $attemptIds = $items->pluck('attempt_id')
                    ->filter(static fn (mixed $id): bool => $id !== null)
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();
                $attempts = $attemptIds === []
                    ? collect()
                    : DB::table('lgt_attempts')->whereIn('id', $attemptIds)->get()->keyBy('id');

                foreach ($items as $item) {
                    $attempt   = $attempts->get((int) ($item->attempt_id ?? 0));
                    $candidate = $attempt === null
                        ? null
                        : ((string) $attempt->state === 'definite_failed'
                            ? $attempt->failure_code
                            : ((string) $attempt->state === 'uncertain' ? $attempt->uncertainty_reason : null));
                    $code = is_string($candidate) && preg_match('/^[A-Z0-9_]{3,96}$/', $candidate) === 1
                        ? $candidate
                        : 'PROCESSING_EXCEPTION';

                    DB::table('lgt_job_items')->where('id', $item->id)->whereNull('failure_code')->update([
                        'failure_code' => $code,
                        'updated_at'   => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Operational failure history is retained during plugin rollback.
    }
};
