<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Account-and-model rolling 60-second RPM/TPM admission control.
 * A database row lock, not a process-local cache, serializes all workers.
 * Reservations remain charged for unknown network outcomes: a timeout may
 * have reached the remote model, and replaying it is not safe.
 * SaaS deployments MUST use control-plane tables keyed by the remote billing
 * identity. Local tenant account IDs are not globally unique; using them as
 * a shared quota key would allow two storefronts to exceed one API account's
 * free-model RPM/TPM. Missing central state fails closed.
 */
final class SiliconFlowRateLimiter
{
    /** @return array{allowed:bool,id:?int,retry_after:int,reason:string} */
    public function acquire(int $accountId, string $model, int $tokenCeiling, int $rpmLimit, int $tpmLimit): array
    {
        if ($tokenCeiling < 1 || $tokenCeiling > 5000000 || $rpmLimit < 1 || $tpmLimit < 1) {
            throw new RuntimeException('INVALID_SILICONFLOW_RATE_CONFIGURATION');
        }

        [$db, $accountTable, $callTable, $identityColumn, $identity] = $this->state($accountId);

        return $db->transaction(function () use ($db, $accountTable, $callTable, $identityColumn, $identity, $model, $tokenCeiling, $rpmLimit, $tpmLimit): array {
            $db->table($accountTable)->insertOrIgnore([
                $identityColumn => $identity,
                'model'               => $model, 'rpm_cap' => $rpmLimit, 'tpm_cap' => $tpmLimit,
                'created_at'          => now(), 'updated_at' => now(),
            ]);
            $row = $db->table($accountTable)
                ->where($identityColumn, $identity)->where('model', $model)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('SILICONFLOW_RATE_STATE_MISSING');
            }

            $now         = now();
            $windowStart = $now->copy()->subSeconds(60);
            $oldWindow   = $row->cap_window_at === null
                || \Illuminate\Support\Carbon::parse($row->cap_window_at)->lessThanOrEqualTo($windowStart);
            // An account/model's limit may be reduced during an active minute;
            // never let a settings edit create capacity retroactively.
            // On a shared remote identity two tenants can supply different
            // caps. Never let a tenant with a higher setting expand the
            // central budget at the next minute boundary. Increasing a
            // central cap must be a deliberate control-plane operation.
            $shared = (bool) config('saas.enabled', false);
            $rpm = ($oldWindow && ! $shared) ? $rpmLimit : min((int) $row->rpm_cap, $rpmLimit);
            $tpm = ($oldWindow && ! $shared) ? $tpmLimit : min((int) $row->tpm_cap, $tpmLimit);
            if ($oldWindow || $rpm !== (int) $row->rpm_cap || $tpm !== (int) $row->tpm_cap) {
                $db->table($accountTable)->where('id', $row->id)->update([
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

            $calls = $db->table($callTable)->where($identityColumn, $identity)
                ->where('model', $model)
                ->where('dispatched_at', '>', $windowStart);
            $count  = (clone $calls)->count();
            $tokens = (int) (clone $calls)->sum('counted_tokens');
            if ($count >= $rpm) {
                return $this->blocked('SILICONFLOW_LOCAL_RPM_LIMIT', 61);
            }
            if ($tokens + $tokenCeiling > $tpm) {
                return $this->blocked('SILICONFLOW_LOCAL_TPM_LIMIT', 61);
            }

            $id = $db->table($callTable)->insertGetId([
                $identityColumn => $identity, 'model' => $model,
                'reserved_tokens'     => $tokenCeiling, 'counted_tokens' => $tokenCeiling,
                'dispatched_at'       => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($row->last_pruned_at === null || $row->last_pruned_at <= $now->copy()->subSeconds(60)) {
                $db->table($callTable)->where('dispatched_at', '<', $now->copy()->subMinutes(2))->delete();
                $db->table($accountTable)->where('id', $row->id)->update(['last_pruned_at' => $now]);
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
        [$db, $accountTable, $callTable, $identityColumn] = $this->state();
        $db->transaction(function () use ($db, $accountTable, $callTable, $identityColumn, $callId, $actualTokens): void {
            $call = $db->table($callTable)->where('id', $callId)->first();
            if ($call === null) {
                return;
            }
            // Reconciliation uses the same model lock as admission to avoid
            // over-admission between a token refund and the next reservation.
            $db->table($accountTable)
                ->where($identityColumn, $call->{$identityColumn})
                ->where('model', $call->model)->lockForUpdate()->first();
            $db->table($callTable)->where('id', $callId)->update([
                'counted_tokens' => $actualTokens, 'updated_at' => now(),
            ]);
        }, 3);
    }

    /** Release only when no byte could possibly have been sent upstream. */
    public function release(int $callId): void
    {
        [$db, $accountTable, $callTable, $identityColumn] = $this->state();
        $db->transaction(function () use ($db, $accountTable, $callTable, $identityColumn, $callId): void {
            $call = $db->table($callTable)->where('id', $callId)->first();
            if ($call === null) {
                return;
            }
            $db->table($accountTable)
                ->where($identityColumn, $call->{$identityColumn})
                ->where('model', $call->model)->lockForUpdate()->first();
            $db->table($callTable)->where('id', $callId)->delete();
        }, 3);
    }

    /** Apply provider 429 Retry-After and exponential account/model cooldown. */
    public function cooldown(int $accountId, string $model, ?int $retryAfter): int
    {
        [$db, $accountTable, , $identityColumn, $identity] = $this->state($accountId);
        return $db->transaction(function () use ($db, $accountTable, $identityColumn, $identity, $model, $retryAfter): int {
            $row = $db->table($accountTable)
                ->where($identityColumn, $identity)->where('model', $model)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('SILICONFLOW_RATE_STATE_MISSING');
            }
            $failures = min(10, (int) $row->consecutive_429 + 1);
            $seconds  = min(600, max($retryAfter ?? 0, 15 * (2 ** min(5, $failures)), 60));
            $until    = now()->addSeconds($seconds);
            $db->table($accountTable)->where('id', $row->id)->update([
                'consecutive_429' => $failures, 'cooldown_until' => $until, 'updated_at' => now(),
            ]);

            return $seconds;
        }, 3);
    }

    public function success(int $accountId, string $model): void
    {
        [$db, $accountTable, , $identityColumn, $identity] = $this->state($accountId);
        $db->table($accountTable)
            ->where($identityColumn, $identity)->where('model', $model)
            ->update(['consecutive_429' => 0, 'updated_at' => now()]);
    }

    /**
     * @return array{0:\Illuminate\Database\Connection,1:string,2:string,3:string,4:int|string|null}
     */
    private function state(?int $accountId = null): array
    {
        if (! (bool) config('saas.enabled', false)) {
            return [DB::connection(), 'lgt_siliconflow_rate_account_models', 'lgt_siliconflow_rate_calls', 'provider_account_id', $accountId];
        }

        $db = DB::connection('saas');
        // Do not silently fall back to tenant-local tables when a deployment
        // has missed its control-plane migration: that would over-admit calls.
        if (! $db->getSchemaBuilder()->hasTable('saas_translation_rate_accounts')
            || ! $db->getSchemaBuilder()->hasTable('saas_translation_rate_calls')) {
            throw new RuntimeException('SAAS_TRANSLATION_RATE_TABLES_MISSING');
        }

        $identity = null;
        if ($accountId !== null) {
            $identity = DB::table('lgt_provider_accounts')->where('id', $accountId)
                ->where('provider', 'siliconflow')->value('billing_identity_hash');
            if (! is_string($identity) || preg_match('/^[a-f0-9]{64}$/D', $identity) !== 1) {
                throw new RuntimeException('SILICONFLOW_BILLING_IDENTITY_MISSING');
            }
        }

        return [$db, 'saas_translation_rate_accounts', 'saas_translation_rate_calls', 'billing_identity_hash', $identity];
    }

    /** @return array{allowed:bool,id:?int,retry_after:int,reason:string} */
    private function blocked(string $reason, int $seconds): array
    {
        return ['allowed' => false, 'id' => null, 'retry_after' => min(600, max(1, $seconds)), 'reason' => $reason];
    }
}
