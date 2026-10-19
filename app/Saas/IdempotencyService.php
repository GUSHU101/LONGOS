<?php

namespace App\Saas;

use App\Saas\Models\IdempotencyRecord;
use App\Saas\Support\IdempotencyConflict;
use App\Saas\Support\IdempotencyPayload;
use App\Saas\Support\IdempotencyResult;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class IdempotencyService
{
    /**
     * @param array<string, mixed>                          $payload
     * @param callable(callable(): void): IdempotencyResult $operation
     */
    public function execute(string $scope, string $key, array $payload, callable $operation): IdempotencyResult
    {
        $hash      = IdempotencyPayload::hash($payload);
        $lockToken = (string) Str::uuid();
        $replay    = $this->claim($scope, $key, $hash, $lockToken);
        if ($replay !== null) {
            return $replay;
        }

        try {
            $result = $operation(fn () => $this->heartbeat($scope, $key, $hash, $lockToken));
            if (! $result instanceof IdempotencyResult) {
                throw new RuntimeException('幂等操作必须返回 IdempotencyResult。');
            }

            $encoded = json_encode($result->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $updated = IdempotencyRecord::query()
                ->where('scope', $scope)
                ->where('idempotency_key', $key)
                ->where('request_hash', $hash)
                ->where('status', IdempotencyRecord::STATUS_PROCESSING)
                ->where('lock_token', $lockToken)
                ->update([
                    'status'              => IdempotencyRecord::STATUS_COMPLETED,
                    'response_status'     => $result->status,
                    'response_ciphertext' => Crypt::encryptString($encoded),
                    'lock_token'          => null,
                    'locked_at'           => null,
                    'updated_at'          => now(),
                ]);
            if ($updated !== 1) {
                throw new IdempotencyConflict('幂等操作租约已失效，本请求不会覆盖新的执行结果。');
            }

            return $result;
        } catch (Throwable $exception) {
            IdempotencyRecord::query()
                ->where('scope', $scope)
                ->where('idempotency_key', $key)
                ->where('request_hash', $hash)
                ->where('lock_token', $lockToken)
                ->update([
                    'status'              => IdempotencyRecord::STATUS_FAILED,
                    'response_status'     => null,
                    'response_ciphertext' => null,
                    'lock_token'          => null,
                    'locked_at'           => null,
                    'updated_at'          => now(),
                ]);

            throw $exception;
        }
    }

    private function claim(string $scope, string $key, string $hash, string $lockToken): ?IdempotencyResult
    {
        return DB::connection('saas')->transaction(function () use ($scope, $key, $hash, $lockToken): ?IdempotencyResult {
            $now       = now();
            $claimed   = false;
            $record    = IdempotencyRecord::query()
                ->where('scope', $scope)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($record?->expires_at?->lte($now)) {
                $record->delete();
                $record = null;
            }

            if ($record === null) {
                $claimed = IdempotencyRecord::query()->insertOrIgnore([
                    'scope'               => $scope,
                    'idempotency_key'     => $key,
                    'request_hash'        => $hash,
                    'status'              => IdempotencyRecord::STATUS_PROCESSING,
                    'response_status'     => null,
                    'response_ciphertext' => null,
                    'lock_token'          => $lockToken,
                    'locked_at'           => $now,
                    'expires_at'          => $now->copy()->addHours(max(1, (int) config('saas.idempotency.ttl_hours', 24))),
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]) === 1;

                $record = IdempotencyRecord::query()
                    ->where('scope', $scope)
                    ->where('idempotency_key', $key)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            if (! hash_equals($record->request_hash, $hash)) {
                throw new IdempotencyConflict('该 Idempotency-Key 已用于不同的请求内容，请为新操作生成新的 UUID。');
            }

            if ($record->status === IdempotencyRecord::STATUS_COMPLETED) {
                if (! is_string($record->response_ciphertext) || $record->response_ciphertext === '') {
                    throw new RuntimeException('幂等响应记录不完整，请联系平台管理员。');
                }

                $body = json_decode(Crypt::decryptString($record->response_ciphertext), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($body)) {
                    throw new RuntimeException('幂等响应记录格式无效。');
                }

                return (new IdempotencyResult($body, (int) $record->response_status))->asReplay();
            }

            $staleBefore = $now->copy()->subMinutes(max(1, (int) config('saas.idempotency.processing_timeout_minutes', 30)));
            if ($record->status === IdempotencyRecord::STATUS_PROCESSING
                && $record->locked_at !== null
                && $record->locked_at->gt($staleBefore)) {
                if (! $claimed) {
                    throw new IdempotencyConflict('相同批量建站请求正在处理中，请稍后使用同一 Idempotency-Key 查询或重试。');
                }
            }

            // Failed operations and stale processing leases are reclaimable.
            // Provisioning itself is resumable by site code and database name.
            $record->forceFill([
                'status'              => IdempotencyRecord::STATUS_PROCESSING,
                'response_status'     => null,
                'response_ciphertext' => null,
                'lock_token'          => $lockToken,
                'locked_at'           => $now,
                'expires_at'          => $now->copy()->addHours(max(1, (int) config('saas.idempotency.ttl_hours', 24))),
            ])->save();

            return null;
        }, 3);
    }

    private function heartbeat(string $scope, string $key, string $hash, string $lockToken): void
    {
        IdempotencyRecord::query()
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->where('request_hash', $hash)
            ->where('status', IdempotencyRecord::STATUS_PROCESSING)
            ->where('lock_token', $lockToken)
            ->update([
                'locked_at'  => now(),
                'expires_at' => now()->addHours(max(1, (int) config('saas.idempotency.ttl_hours', 24))),
                'updated_at' => now(),
            ]);

        // MySQL reports changed rows, not matched rows. Two heartbeats within
        // the same second can write identical TIMESTAMP values and therefore
        // return 0 even though this worker still owns the lease. Verify the
        // ownership predicate itself after the refresh instead of treating
        // the affected-row count as a compare-and-swap result.
        $stillOwned = IdempotencyRecord::query()
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->where('request_hash', $hash)
            ->where('status', IdempotencyRecord::STATUS_PROCESSING)
            ->where('lock_token', $lockToken)
            ->exists();

        if (! $stillOwned) {
            throw new IdempotencyConflict('幂等操作租约已失效，已停止继续处理。');
        }
    }
}
