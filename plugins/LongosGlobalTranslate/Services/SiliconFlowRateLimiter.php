<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tenant-local, model-wide rolling 60-second RPM/TPM admission control.
 * A database row lock, not a process-local cache, serializes all workers.
 * Reservations remain charged for unknown network outcomes: a timeout may
 * have reached the remote model, and replaying it is not safe.
 */
final class SiliconFlowRateLimiter
{
    /** @return array{allowed:bool,id:?int,retry_after:int,reason:string} */
    public function acquire(int $accountId, string $model, int $tokenCeiling, int $rpmLimit, int $tpmLimit): array
    {
        if ($tokenCeiling < 1 || $tokenCeiling > 5000000 || $rpmLimit < 1 || $tpmLimit < 1) {
            throw new RuntimeException('INVALID_SILICONFLOW_RATE_CONFIGURATION');
        }

        return DB::transaction(function () use ($accountId, $model, $tokenCeiling, $rpmLimit, $tpmLimit): array {
            DB::table('lgt_siliconflow_rate_models')->insertOrIgnore([
                'model'      => $model, 'rpm_cap' => $rpmLimit, 'tpm_cap' => $tpmLimit,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('lgt_siliconflow_rate_models')->where('model', $model)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('SILICONFLOW_RATE_STATE_MISSING');
            }

            $now         = now();
            $windowStart = $now->copy()->subSeconds(60);
            $oldWindow   = $row->cap_window_at === null
                || \Illuminate\Support\Carbon::parse($row->cap_window_at)->lessThanOrEqualTo($windowStart);
            // If multiple keys target one model, use the lowest configured
            // limit observed in the active minute; keys do not grant quotas.
            $rpm = $oldWindow ? $rpmLimit : min((int) $row->rpm_cap, $rpmLimit);
            $tpm = $oldWindow ? $tpmLimit : min((int) $row->tpm_cap, $tpmLimit);
            if ($oldWindow || $rpm !== (int) $row->rpm_cap || $tpm !== (int) $row->tpm_cap) {
                DB::table('lgt_siliconflow_rate_models')->where('id', $row->id)->update([
                    'rpm_cap'       => $rpm, 'tpm_cap' => $tpm,
                    'cap_window_at' => $oldWindow ? $now : $row->cap_window_at,
                    'updated_at'    => $now,
                ]);
            }

            $cooldown = $row->cooldown_until === null ? null : \Illuminate\Support\Carbon::parse($row->cooldown_until);
            if ($cooldown !== null && $cooldown->greaterThan($now)) {
                return $this->blocked('SILICONFLOW_REMOTE_COOLDOWN', $now->diffInSeconds($cooldown) + 1);
            }
            if ($tokenCeiling > $tpm) {
                return $this->blocked('SILICONFLOW_SEGMENT_EXCEEDS_TPM', 60);
            }

            $calls = DB::table('lgt_siliconflow_rate_calls')->where('model', $model)
                ->where('dispatched_at', '>', $windowStart);
            $count  = (clone $calls)->count();
            $tokens = (int) (clone $calls)->sum('counted_tokens');
            if ($count >= $rpm) {
                return $this->blocked('SILICONFLOW_LOCAL_RPM_LIMIT', 61);
            }
            if ($tokens + $tokenCeiling > $tpm) {
                return $this->blocked('SILICONFLOW_LOCAL_TPM_LIMIT', 61);
            }

            $id = DB::table('lgt_siliconflow_rate_calls')->insertGetId([
                'provider_account_id' => $accountId, 'model' => $model,
                'reserved_tokens'     => $tokenCeiling, 'counted_tokens' => $tokenCeiling,
                'dispatched_at'       => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($row->last_pruned_at === null || $row->last_pruned_at <= $now->copy()->subSeconds(60)) {
                DB::table('lgt_siliconflow_rate_calls')->where('dispatched_at', '<', $now->copy()->subMinutes(2))->delete();
                DB::table('lgt_siliconflow_rate_models')->where('id', $row->id)->update(['last_pruned_at' => $now]);
            }

            return ['allowed' => true, 'id' => (int) $id, 'retry_after' => 0, 'reason' => ''];
        }, 3);
    }

    /** Reconcile against real API usage, retaining reservation on unknown delivery. */
    public function settle(int $callId, ?int $actualTokens): void
    {
        if ($actualTokens === null || $actualTokens < 0) {
            return;
        }
        DB::transaction(function () use ($callId, $actualTokens): void {
            $call = DB::table('lgt_siliconflow_rate_calls')->where('id', $callId)->first();
            if ($call === null) {
                return;
            }
            // Reconciliation uses the same model lock as admission to avoid
            // over-admission between a token refund and the next reservation.
            DB::table('lgt_siliconflow_rate_models')->where('model', $call->model)->lockForUpdate()->first();
            DB::table('lgt_siliconflow_rate_calls')->where('id', $callId)->update([
                'counted_tokens' => $actualTokens, 'updated_at' => now(),
            ]);
        }, 3);
    }

    /** Release only when no byte could possibly have been sent upstream. */
    public function release(int $callId): void
    {
        DB::transaction(function () use ($callId): void {
            $call = DB::table('lgt_siliconflow_rate_calls')->where('id', $callId)->first();
            if ($call === null) {
                return;
            }
            DB::table('lgt_siliconflow_rate_models')->where('model', $call->model)->lockForUpdate()->first();
            DB::table('lgt_siliconflow_rate_calls')->where('id', $callId)->delete();
        }, 3);
    }

    /** Apply provider 429 Retry-After and exponential per-model cooldown. */
    public function cooldown(string $model, ?int $retryAfter): int
    {
        return DB::transaction(function () use ($model, $retryAfter): int {
            $row = DB::table('lgt_siliconflow_rate_models')->where('model', $model)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('SILICONFLOW_RATE_STATE_MISSING');
            }
            $failures = min(10, (int) $row->consecutive_429 + 1);
            $seconds  = min(600, max($retryAfter ?? 0, 15 * (2 ** min(5, $failures)), 60));
            $until    = now()->addSeconds($seconds);
            DB::table('lgt_siliconflow_rate_models')->where('id', $row->id)->update([
                'consecutive_429' => $failures, 'cooldown_until' => $until, 'updated_at' => now(),
            ]);

            return $seconds;
        }, 3);
    }

    public function success(string $model): void
    {
        DB::table('lgt_siliconflow_rate_models')->where('model', $model)
            ->update(['consecutive_429' => 0, 'updated_at' => now()]);
    }

    /** @return array{allowed:bool,id:?int,retry_after:int,reason:string} */
    private function blocked(string $reason, int $seconds): array
    {
        return ['allowed' => false, 'id' => null, 'retry_after' => min(600, max(1, $seconds)), 'reason' => $reason];
    }
}
