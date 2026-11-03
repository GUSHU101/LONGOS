<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * A durable local safety ledger. It deliberately models only tenant-owned
 * provider accounts: platform-pool allocation requires a separate control
 * database and must not be silently emulated in a tenant connection.
 */
final class UsageLedger
{
    public const RESERVED = 'reserved';

    public const SENDING = 'sending';

    public const COMMITTED = 'committed';

    public const DEFINITE_FAILED = 'definite_failed';

    public const UNCERTAIN = 'uncertain';

    /** @return array{id:int,request_uuid:string,billing_cycle:string,units:int,state:string} */
    public function reserve(int $accountId, int $units, ?int $jobItemId = null, ?string $requestUuid = null, ?CarbonImmutable $now = null, bool $controlledVerification = false): array
    {
        if ($units < 1) {
            throw new InvalidArgumentException('INVALID_RESERVATION_UNITS');
        }
        $requestUuid ??= (string) Str::uuid();
        $now         ??= CarbonImmutable::now('UTC');

        return DB::transaction(function () use ($accountId, $units, $jobItemId, $requestUuid, $now, $controlledVerification): array {
            $account = DB::table('lgt_provider_accounts')->where('id', $accountId)->lockForUpdate()->first();
            if ($account === null || $account->owner_scope !== 'tenant' || ! $account->enabled) {
                throw new RuntimeException('PROVIDER_ACCOUNT_NOT_DISPATCHABLE');
            }
            if (! $controlledVerification && (! $account->dispatch_enabled || $account->verified_at === null)) {
                throw new RuntimeException('PROVIDER_ACCOUNT_NOT_DISPATCHABLE');
            }
            if ((int) $account->paid_cap !== 0) {
                throw new RuntimeException('AUTOMATIC_PAID_OVERAGE_FORBIDDEN');
            }
            $cycle = $now->setTimezone((string) $account->cycle_timezone)->format('Y-m');
            DB::table('lgt_usage_months')->insertOrIgnore([
                'provider_account_id'   => $accountId,
                'billing_cycle'         => $cycle,
                'external_usage_buffer' => (int) $account->external_usage_buffer,
                'soft_limit'            => $this->cap($account),
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);
            $month = DB::table('lgt_usage_months')
                ->where('provider_account_id', $accountId)
                ->where('billing_cycle', $cycle)
                ->lockForUpdate()
                ->first();
            if ($month === null) {
                throw new RuntimeException('USAGE_MONTH_UNAVAILABLE');
            }
            $existing = DB::table('lgt_attempts')->where('request_uuid', $requestUuid)->lockForUpdate()->first();
            if ($existing !== null) {
                return ['id' => (int) $existing->id, 'request_uuid' => $requestUuid, 'billing_cycle' => (string) $existing->billing_cycle, 'units' => (int) $existing->source_units_reserved, 'state' => (string) $existing->state];
            }
            $occupied = (int) $month->committed_units + (int) $month->reserved_units + (int) $month->sending_units + (int) $month->uncertain_units + (int) $month->external_usage_buffer;
            if ($occupied + $units > (int) $month->soft_limit) {
                throw new RuntimeException('LOCAL_BUDGET_EXHAUSTED');
            }
            $attemptId = DB::table('lgt_attempts')->insertGetId([
                'request_uuid'           => $requestUuid,
                'provider_account_id'    => $accountId,
                'job_item_id'            => $jobItemId,
                'billing_cycle'          => $cycle,
                'state'                  => self::RESERVED,
                'batch_hash'             => hash('sha256', $requestUuid . "\0" . $units),
                'source_units_estimated' => $units,
                'source_units_reserved'  => $units,
                'request_fingerprint'    => hash('sha256', $accountId . "\0" . $requestUuid),
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);
            DB::table('lgt_usage_months')->where('id', $month->id)->update([
                'reserved_units' => (int) $month->reserved_units + $units,
                'version'        => (int) $month->version        + 1,
                'updated_at'     => $now,
            ]);
            $this->event((int) $month->id, $attemptId, 'reservation', $units, null, null, $now);

            return ['id' => $attemptId, 'request_uuid' => $requestUuid, 'billing_cycle' => $cycle, 'units' => $units, 'state' => self::RESERVED];
        }, 3);
    }

    /** Mark journaled immediately before the external HTTP request, after transaction commit. */
    public function markSending(int $attemptId, ?CarbonImmutable $now = null): void
    {
        $this->transition($attemptId, self::RESERVED, self::SENDING, $now, 'sending');
    }

    public function commit(int $attemptId, string $providerRequestId, ?CarbonImmutable $now = null): void
    {
        $this->transition($attemptId, self::SENDING, self::COMMITTED, $now, 'commit', [
            'provider_request_id' => mb_substr($providerRequestId, 0, 128),
            'finished_at'         => $now ?? CarbonImmutable::now('UTC'),
        ]);
    }

    /**
     * Make a successful dictionary write and a committed ledger state one
     * database unit. The callback must not make an HTTP request.
     */
    public function commitWith(int $attemptId, string $providerRequestId, callable $writeTranslation, ?CarbonImmutable $now = null): mixed
    {
        return DB::transaction(function () use ($attemptId, $providerRequestId, $writeTranslation, $now): mixed {
            $result = $writeTranslation();
            $this->commit($attemptId, $providerRequestId, $now);

            return $result;
        }, 3);
    }

    /** Release only a locally provable, non-billable failure. */
    public function definiteFailure(int $attemptId, string $code, ?CarbonImmutable $now = null): void
    {
        $this->transition($attemptId, [self::RESERVED, self::SENDING], self::DEFINITE_FAILED, $now, 'release', [
            'failure_code' => mb_substr($code, 0, 64),
            'finished_at'  => $now ?? CarbonImmutable::now('UTC'),
        ]);
    }

    /** A sent request that cannot be proven unbilled remains occupied. */
    public function uncertain(int $attemptId, string $reason, ?CarbonImmutable $now = null): void
    {
        $this->transition($attemptId, self::SENDING, self::UNCERTAIN, $now, 'uncertain', [
            'uncertainty_reason' => mb_substr($reason, 0, 96),
            'finished_at'        => $now ?? CarbonImmutable::now('UTC'),
        ]);
    }

    /** @param string|list<string> $from @param array<string, mixed> $patch */
    private function transition(int $attemptId, string|array $from, string $to, ?CarbonImmutable $now, string $event, array $patch = []): void
    {
        $now ??= CarbonImmutable::now('UTC');
        DB::transaction(function () use ($attemptId, $from, $to, $now, $event, $patch): void {
            $attempt = DB::table('lgt_attempts')->where('id', $attemptId)->lockForUpdate()->first();
            if ($attempt === null) {
                throw new RuntimeException('ATTEMPT_NOT_FOUND');
            }
            $allowed = (array) $from;
            if ((string) $attempt->state === $to) {
                return;
            }
            if (! in_array((string) $attempt->state, $allowed, true)) {
                throw new RuntimeException('INVALID_ATTEMPT_TRANSITION');
            }
            $month = DB::table('lgt_usage_months')
                ->where('provider_account_id', $attempt->provider_account_id)
                ->where('billing_cycle', $attempt->billing_cycle)
                ->lockForUpdate()
                ->first();
            if ($month === null) {
                throw new RuntimeException('USAGE_MONTH_NOT_FOUND');
            }
            $units        = (int) $attempt->source_units_reserved;
            $updates      = ['state' => $to, 'updated_at' => $now, ...$patch];
            $monthUpdates = ['version' => (int) $month->version + 1, 'updated_at' => $now];
            if ($to === self::SENDING) {
                $monthUpdates['reserved_units'] = max(0, (int) $month->reserved_units - $units);
                $monthUpdates['sending_units']  = (int) $month->sending_units + $units;
                $updates['sent_at']             = $now;
            } elseif ($to === self::COMMITTED) {
                $monthUpdates['sending_units']   = max(0, (int) $month->sending_units - $units);
                $monthUpdates['committed_units'] = (int) $month->committed_units + $units;
            } elseif ($to === self::UNCERTAIN) {
                $monthUpdates['sending_units']   = max(0, (int) $month->sending_units - $units);
                $monthUpdates['uncertain_units'] = (int) $month->uncertain_units + $units;
            } elseif ($to === self::DEFINITE_FAILED) {
                $field                = (string) $attempt->state === self::RESERVED ? 'reserved_units' : 'sending_units';
                $monthUpdates[$field] = max(0, (int) $month->{$field} - $units);
            }
            DB::table('lgt_attempts')->where('id', $attemptId)->update($updates);
            DB::table('lgt_usage_months')->where('id', $month->id)->update($monthUpdates);
            $this->event((int) $month->id, $attemptId, $event, $units, null, $patch['failure_code'] ?? $patch['uncertainty_reason'] ?? null, $now);
        }, 3);
    }

    private function cap(object $account): int
    {
        $quota      = (int) $account->free_quota;
        $configured = (int) $account->soft_cap;
        $cap        = $configured > 0 ? min($quota, $configured) : (int) floor($quota * 0.9);

        return max(0, $cap);
    }

    private function event(int $monthId, int $attemptId, string $event, int $units, ?int $actorId, ?string $reason, CarbonImmutable $now): void
    {
        DB::table('lgt_usage_events')->insert([
            'usage_month_id' => $monthId,
            'attempt_id'     => $attemptId,
            'event'          => $event,
            'units'          => $units,
            'actor_id'       => $actorId,
            'reason'         => $reason,
            'metadata'       => null,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
    }
}
