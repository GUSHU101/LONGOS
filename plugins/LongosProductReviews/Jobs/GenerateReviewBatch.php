<?php

namespace Plugin\LongosProductReviews\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Plugin\LongosProductReviews\Exceptions\GenerationBatchException;
use Plugin\LongosProductReviews\Exceptions\SiliconFlowException;
use Plugin\LongosProductReviews\Models\ReviewGenerationBatch;
use Plugin\LongosProductReviews\Services\ReviewGenerationBatchService;
use Plugin\LongosProductReviews\Services\ReviewGenerationService;
use Spatie\Multitenancy\Jobs\TenantAware;
use Throwable;

class GenerateReviewBatch implements ShouldQueue, TenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3000;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $batchId) {}

    public function handle(ReviewGenerationBatchService $batches, ReviewGenerationService $generation): void
    {
        $batch = $batches->claim($this->batchId);
        if (! $batch) {
            return;
        }

        try {
            $request = $batches->requestData($batch);
            // Freeze each queued batch to the model selected at creation time, even if
            // an administrator changes the plugin setting while the job is waiting.
            $request['_model'] = $batch->model;
            $result            = $generation->preview(
                $request,
                fn (int $processed): mixed => $batches->progress($batch, $processed),
                $batch->id,
            );
            $batches->complete($batch, $result);
        } catch (Throwable $exception) {
            if (! $exception instanceof SiliconFlowException && ! $exception instanceof GenerationBatchException) {
                report($exception);
            }
            $message = $exception instanceof SiliconFlowException || $exception instanceof GenerationBatchException
                ? $exception->getMessage()
                : trans('LongosProductReviews::reviews.ai_batch_failed');
            $batches->fail($batch, $message);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $batch = ReviewGenerationBatch::query()->find($this->batchId);
        if ($batch && in_array($batch->status, [ReviewGenerationBatch::STATUS_PENDING, ReviewGenerationBatch::STATUS_RUNNING], true)) {
            app(ReviewGenerationBatchService::class)->fail(
                $batch,
                trans('LongosProductReviews::reviews.ai_batch_worker_failed'),
            );
        }
        if ($exception) {
            report($exception);
        }
    }
}
