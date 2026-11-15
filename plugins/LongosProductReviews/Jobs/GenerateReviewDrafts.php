<?php

namespace Plugin\LongosProductReviews\Jobs;

use Beike\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugin\LongosProductReviews\Models\Review;
use Plugin\LongosProductReviews\Services\SiliconFlowReviewDrafts;
use Throwable;

/**
 * One product per job. Batch jobs are dispatched as a sequential chain,
 * avoiding simultaneous requests from one store against the free endpoint.
 */
final class GenerateReviewDrafts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 55;

    public int $tries = 3;

    public function __construct(
        public readonly string $batchId,
        public readonly int $productId,
        public readonly int $count,
        public readonly string $language,
        public readonly string $scope,
    ) {
        $this->onQueue('default');
    }

    public static function cacheKey(string $scope, string $batchId): string
    {
        return 'longos-reviews:ai:' . $scope . ':' . $batchId;
    }

    public function handle(SiliconFlowReviewDrafts $generator): void
    {
        $key = self::cacheKey($this->scope, $this->batchId);
        $itemKey = $key . ':product:' . $this->productId;
        if (Cache::get($itemKey) === 'done') {
            return;
        }

        $existingState = Cache::get($key);
        if (is_array($existingState) && ($existingState['status'] ?? '') === 'queued') {
            $existingState['status'] = 'running';
            Cache::put($key, $existingState, now()->addDays(2));
        }

        $created = 0;
        $error = null;

        try {
            $product = Product::query()->where('active', true)->findOrFail($this->productId);
            $drafts = $generator->generate($product, $this->count, $this->language);
            $created = DB::transaction(function () use ($drafts, $product): int {
                $inserted = 0;
                foreach ($drafts as $draft) {
                    // Never overwrite customer submissions or existing drafts.
                    if (Review::query()->where('product_id', $product->id)->where('body', $draft['body'])->exists()) {
                        continue;
                    }

                    Review::query()->create([
                        'product_id' => $product->id,
                        'author_name' => SiliconFlowReviewDrafts::AUTHOR_PREFIX . $draft['author_name'],
                        'author_country' => $draft['author_country'],
                        'rating' => $draft['rating'],
                        'title' => '[AI Sample: ' . $draft['sample_name'] . '] ' . $draft['title'],
                        'body' => $draft['body'],
                        'images' => [],
                        'verified_purchase' => false,
                        'ai_generated' => true,
                        'published' => false,
                        'sort_order' => 0,
                        'reviewed_at' => now()->subDays(random_int(0, 90)),
                    ]);
                    $inserted++;
                }

                return $inserted;
            });
        } catch (Throwable $exception) {
            $reason = $exception->getMessage();
            if (in_array($reason, ['SILICONFLOW_HTTP_429', 'SILICONFLOW_HTTP_503'], true)
                && $this->attempts() < $this->tries) {
                $this->release(20 * max(1, $this->attempts()));

                return;
            }
            $error = preg_match('/^[A-Z0-9_]+$/', $reason) ? $reason : 'GENERATION_FAILED';
            Log::warning('LONGOS review AI draft generation failed', [
                'product_id' => $this->productId,
                'batch_id' => $this->batchId,
                'code' => $error,
            ]);
        }

        // A chained batch has only one executing item at a time; status is
        // written in the same tenant-specific cache context as the request.
        $state = Cache::get($key);
        if (! is_array($state)) {
            return;
        }

        $state['completed'] = ((int) ($state['completed'] ?? 0)) + 1;
        $state['created'] = ((int) ($state['created'] ?? 0)) + $created;
        $state['last_product_id'] = $this->productId;
        if ($error !== null) {
            $state['failed'] = ((int) ($state['failed'] ?? 0)) + 1;
            $state['recent_errors'] = array_slice(array_merge((array) ($state['recent_errors'] ?? []), [
                ['product_id' => $this->productId, 'code' => $error],
            ]), -10);
        }
        if ($state['completed'] >= $state['total']) {
            $state['status'] = 'finished';
            $state['finished_at'] = now()->toIso8601String();
        }
        Cache::put($key, $state, now()->addDays(2));
        Cache::put($itemKey, 'done', now()->addDays(2));
    }
}
