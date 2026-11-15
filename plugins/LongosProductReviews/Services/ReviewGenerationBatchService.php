<?php

namespace Plugin\LongosProductReviews\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Plugin\LongosProductReviews\Exceptions\GenerationBatchException;
use Plugin\LongosProductReviews\Models\ReviewGenerationBatch;

class ReviewGenerationBatchService
{
    private const PREVIEW_TTL_MINUTES = 10_080;

    /** @param array<string, mixed> $request */
    public function create(array $request, string $model): ReviewGenerationBatch
    {
        $ownerId = (int) (current_user()?->id ?? 0);
        if ($ownerId < 1) {
            throw new GenerationBatchException(trans('LongosProductReviews::reviews.ai_batch_owner_missing'));
        }
        $model = trim($model);
        if ($model === '' || mb_strlen($model) > 160) {
            throw new GenerationBatchException(trans('LongosProductReviews::reviews.ai_error_model'));
        }

        $productId  = (int) $request['product_id'];
        $connection = DB::connection();
        $lockScope  = implode("\0", [
            (string) (current_site()?->getKey() ?? current_site()?->code ?? ''),
            (string) $connection->getName(),
            (string) $connection->getDatabaseName(),
            (string) $connection->getTablePrefix(),
            (string) $ownerId,
            (string) $productId,
        ]);

        try {
            return Cache::lock('longos:product-reviews:create:' . hash('sha256', $lockScope), 15)
                ->block(8, fn (): ReviewGenerationBatch => DB::transaction(
                    fn (): ReviewGenerationBatch => $this->createOrReuse($request, $model, $ownerId, $productId),
                ));
        } catch (LockTimeoutException) {
            $active = $this->activeOwnedProductBatch($ownerId, $productId);
            if ($active !== null) {
                return $active;
            }

            throw new GenerationBatchException(trans('LongosProductReviews::reviews.ai_batch_create_busy'));
        }
    }

    public function findOwned(string $id): ?ReviewGenerationBatch
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return ReviewGenerationBatch::query()
            ->with('product.description')
            ->whereKey($id)
            ->where('admin_user_id', (int) (current_user()?->id ?? 0))
            ->first();
    }

    /** @return Collection<int, ReviewGenerationBatch> */
    public function recentOwned(int $limit = 8): Collection
    {
        $ownerId = (int) (current_user()?->id ?? 0);
        if ($ownerId < 1) {
            return new Collection;
        }

        return ReviewGenerationBatch::query()
            ->with('product.description')
            ->where('admin_user_id', $ownerId)
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->limit(min(20, max(1, $limit)))
            ->get();
    }

    public function latestResumableOwned(): ?ReviewGenerationBatch
    {
        $ownerId = (int) (current_user()?->id ?? 0);
        if ($ownerId < 1) {
            return null;
        }

        return ReviewGenerationBatch::query()
            ->with('product.description')
            ->where('admin_user_id', $ownerId)
            ->whereIn('status', [
                ReviewGenerationBatch::STATUS_PENDING,
                ReviewGenerationBatch::STATUS_RUNNING,
                ReviewGenerationBatch::STATUS_COMPLETED,
            ])
            ->where('expires_at', '>', now())
            ->orderByRaw('CASE status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [
                ReviewGenerationBatch::STATUS_RUNNING,
                ReviewGenerationBatch::STATUS_PENDING,
            ])
            ->latest('created_at')
            ->first();
    }

    /** @return array<string, mixed> */
    public function requestData(ReviewGenerationBatch $batch): array
    {
        return $this->decrypt($batch->request_ciphertext);
    }

    /** @return array<string, mixed>|null */
    public function result(ReviewGenerationBatch $batch): ?array
    {
        if ($batch->result_ciphertext === null || $batch->result_ciphertext === '') {
            return null;
        }

        return $this->decrypt($batch->result_ciphertext);
    }

    public function claim(string $id): ?ReviewGenerationBatch
    {
        $claimed = ReviewGenerationBatch::query()
            ->whereKey($id)
            ->where('status', ReviewGenerationBatch::STATUS_PENDING)
            ->update([
                'status'     => ReviewGenerationBatch::STATUS_RUNNING,
                'started_at' => now(),
                'error'      => null,
                'updated_at' => now(),
            ]);

        return $claimed === 1 ? ReviewGenerationBatch::query()->find($id) : null;
    }

    public function progress(ReviewGenerationBatch $batch, int $processed): bool
    {
        return ReviewGenerationBatch::query()
            ->whereKey($batch->id)
            ->where('status', ReviewGenerationBatch::STATUS_RUNNING)
            ->update([
                'processed_count' => min($batch->requested_count, max(0, $processed)),
                'updated_at'      => now(),
            ]) === 1;
    }

    /** @param array<string, mixed> $result */
    public function complete(ReviewGenerationBatch $batch, array $result): void
    {
        $items   = $result['items'] ?? null;
        $specIds = is_array($items)
            ? array_map(static fn (mixed $item): string => is_array($item) ? (string) ($item['spec_id'] ?? '') : '', $items)
            : [];
        if (($result['batch_id'] ?? null)         !== $batch->id
            || (int) ($result['product_id'] ?? 0) !== $batch->product_id
            || (string) ($result['model'] ?? '')  !== $batch->model
            || ! is_array($items)
            || ! array_is_list($items)
            || count($items)                 !== $batch->requested_count
            || count(array_unique($specIds)) !== $batch->requested_count
            || in_array('', $specIds, true)) {
            throw new GenerationBatchException(trans('LongosProductReviews::reviews.ai_error_count'));
        }

        ReviewGenerationBatch::query()
            ->whereKey($batch->id)
            ->where('status', ReviewGenerationBatch::STATUS_RUNNING)
            ->update([
                'status'            => ReviewGenerationBatch::STATUS_COMPLETED,
                'processed_count'   => $batch->requested_count,
                'result_ciphertext' => $this->encrypt($result),
                'error'             => null,
                'finished_at'       => now(),
                'updated_at'        => now(),
            ]);
    }

    public function fail(ReviewGenerationBatch $batch, string $message): void
    {
        ReviewGenerationBatch::query()
            ->whereKey($batch->id)
            ->whereIn('status', [
                ReviewGenerationBatch::STATUS_PENDING,
                ReviewGenerationBatch::STATUS_RUNNING,
                ReviewGenerationBatch::STATUS_COMPLETED,
            ])
            ->update([
                'status'      => ReviewGenerationBatch::STATUS_FAILED,
                'error'       => Str::limit(trim($message), 1000, ''),
                'finished_at' => now(),
                'updated_at'  => now(),
            ]);
    }

    public function requeue(ReviewGenerationBatch $batch): bool
    {
        return ReviewGenerationBatch::query()
            ->whereKey($batch->id)
            ->where('status', ReviewGenerationBatch::STATUS_RUNNING)
            ->update([
                'status'          => ReviewGenerationBatch::STATUS_PENDING,
                'processed_count' => 0,
                'started_at'      => null,
                'error'           => null,
                'updated_at'      => now(),
            ]) === 1;
    }

    public function cancel(string $id): ReviewGenerationBatch
    {
        return DB::transaction(function () use ($id): ReviewGenerationBatch {
            $batch = ReviewGenerationBatch::query()
                ->whereKey($id)
                ->where('admin_user_id', (int) (current_user()?->id ?? 0))
                ->lockForUpdate()
                ->first();
            if (! $batch) {
                throw new GenerationBatchException(trans('LongosProductReviews::reviews.ai_preview_expired'));
            }
            if (! in_array($batch->status, [
                ReviewGenerationBatch::STATUS_PENDING,
                ReviewGenerationBatch::STATUS_RUNNING,
                ReviewGenerationBatch::STATUS_CANCELLED,
            ], true)) {
                throw new GenerationBatchException(trans('LongosProductReviews::reviews.ai_batch_not_cancellable'));
            }
            if (in_array($batch->status, [ReviewGenerationBatch::STATUS_PENDING, ReviewGenerationBatch::STATUS_RUNNING], true)) {
                $batch->forceFill([
                    'status'      => ReviewGenerationBatch::STATUS_CANCELLED,
                    'error'       => null,
                    'finished_at' => now(),
                    'updated_at'  => now(),
                ])->saveOrFail();
            }

            return $batch->refresh();
        });
    }

    /** @param list<string> $selectedIds
     *  @return Collection<int, \Plugin\LongosProductReviews\Models\Review>
     */
    public function commit(string $id, ReviewService $reviews, array $selectedIds): Collection
    {
        return DB::transaction(function () use ($id, $reviews, $selectedIds): Collection {
            $batch = ReviewGenerationBatch::query()
                ->whereKey($id)
                ->where('admin_user_id', (int) (current_user()?->id ?? 0))
                ->lockForUpdate()
                ->first();

            if (! $batch || $batch->status !== ReviewGenerationBatch::STATUS_COMPLETED || $batch->expires_at?->isPast()) {
                throw new GenerationBatchException(trans('LongosProductReviews::reviews.ai_preview_expired'));
            }
            $result = $this->result($batch);
            if (! is_array($result) || ! is_array($result['items'] ?? null)) {
                throw new GenerationBatchException(trans('LongosProductReviews::reviews.ai_error_schema'));
            }

            $selected = array_fill_keys(array_map('strval', $selectedIds), true);
            $items    = array_values(array_filter(
                $result['items'],
                static fn (mixed $item): bool => is_array($item) && isset($selected[(string) ($item['spec_id'] ?? '')]),
            ));
            if ($items === [] || count($items) !== count($selected)) {
                throw new GenerationBatchException(trans('LongosProductReviews::reviews.ai_preview_selection_invalid'));
            }
            $validator = Validator::make(['items' => $items], [
                'items'               => ['required', 'array', 'min:1', 'max:30'],
                'items.*.product_id'  => ['required', 'integer', 'in:' . $batch->product_id],
                'items.*.spec_id'     => ['required', 'string', 'regex:/^[1-9][0-9]*$/'],
                'items.*.author_name' => ['required', 'string', 'max:120'],
                'items.*.country'     => ['nullable', 'string', 'max:80'],
                'items.*.rating'      => ['required', 'integer', 'between:1,5'],
                'items.*.title'       => ['required', 'string', 'max:160'],
                'items.*.body'        => ['required', 'string', 'min:10', 'max:5000'],
                'items.*.reviewed_at' => ['required', 'date', 'before_or_equal:now'],
            ]);
            if ($validator->fails()) {
                throw new GenerationBatchException(trans('LongosProductReviews::reviews.ai_error_schema'));
            }

            $created = $reviews->createGeneratedDrafts($items, $batch->id);
            $batch->forceFill([
                'status'     => ReviewGenerationBatch::STATUS_COMMITTED,
                'updated_at' => now(),
            ])->saveOrFail();

            return $created;
        });
    }

    public function pruneExpired(): int
    {
        return ReviewGenerationBatch::query()
            ->where('expires_at', '<', now())
            ->where('status', '!=', ReviewGenerationBatch::STATUS_RUNNING)
            ->delete();
    }

    public function recoverStale(): int
    {
        $pending = ReviewGenerationBatch::query()
            ->where('status', ReviewGenerationBatch::STATUS_PENDING)
            ->where('updated_at', '<', now()->subMinutes(30))
            ->update([
                'status'      => ReviewGenerationBatch::STATUS_FAILED,
                'error'       => trans('LongosProductReviews::reviews.ai_batch_queue_stalled'),
                'finished_at' => now(),
                'updated_at'  => now(),
            ]);
        $running = ReviewGenerationBatch::query()
            ->where('status', ReviewGenerationBatch::STATUS_RUNNING)
            ->where('updated_at', '<', now()->subMinutes(55))
            ->update([
                'status'      => ReviewGenerationBatch::STATUS_FAILED,
                'error'       => trans('LongosProductReviews::reviews.ai_batch_worker_stalled'),
                'finished_at' => now(),
                'updated_at'  => now(),
            ]);

        return $pending + $running;
    }

    public function recoverIfStale(ReviewGenerationBatch $batch): ReviewGenerationBatch
    {
        if ($batch->status === ReviewGenerationBatch::STATUS_PENDING
            && $batch->updated_at?->lt(now()->subMinutes(30))) {
            $this->fail($batch, trans('LongosProductReviews::reviews.ai_batch_queue_stalled'));
        } elseif ($batch->status === ReviewGenerationBatch::STATUS_RUNNING
            && $batch->updated_at?->lt(now()->subMinutes(55))) {
            $this->fail($batch, trans('LongosProductReviews::reviews.ai_batch_worker_stalled'));
        }

        return $batch->refresh();
    }

    /** @param array<string, mixed> $value */
    private function encrypt(array $value): string
    {
        return Crypt::encryptString(json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        ));
    }

    /** @return array<string, mixed> */
    private function decrypt(string $ciphertext): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($ciphertext), true, 128, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new GenerationBatchException(
                trans('LongosProductReviews::reviews.ai_batch_decrypt_failed'),
                previous: $exception,
            );
        }
        if (! is_array($decoded)) {
            throw new GenerationBatchException(trans('LongosProductReviews::reviews.ai_batch_decrypt_failed'));
        }

        return $decoded;
    }

    /** @param array<string, mixed> $request */
    private function createOrReuse(array $request, string $model, int $ownerId, int $productId): ReviewGenerationBatch
    {
        // The distributed lock covers the empty-row race; this row lock covers
        // updates once an active batch already exists.
        $active = $this->activeOwnedProductBatch($ownerId, $productId, true);
        if ($active !== null) {
            return $active;
        }

        return ReviewGenerationBatch::query()->create([
            'id'                 => (string) Str::uuid(),
            'admin_user_id'      => $ownerId,
            'product_id'         => $productId,
            'status'             => ReviewGenerationBatch::STATUS_PENDING,
            'model'              => $model,
            'requested_count'    => (int) $request['count'],
            'processed_count'    => 0,
            'request_ciphertext' => $this->encrypt($request),
            'expires_at'         => now()->addMinutes(self::PREVIEW_TTL_MINUTES),
        ]);
    }

    private function activeOwnedProductBatch(int $ownerId, int $productId, bool $lock = false): ?ReviewGenerationBatch
    {
        $query = ReviewGenerationBatch::query()
            ->where('admin_user_id', $ownerId)
            ->where('product_id', $productId)
            ->whereIn('status', [
                ReviewGenerationBatch::STATUS_PENDING,
                ReviewGenerationBatch::STATUS_RUNNING,
            ])
            ->where('expires_at', '>', now())
            ->latest('created_at');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }
}
