@php
  $currentBatchId = $currentBatchId ?? null;
  $watchTasks = $watchTasks ?? false;
  $showEmpty = $showEmpty ?? false;
  $statusClasses = [
    \Plugin\LongosProductReviews\Models\ReviewGenerationBatch::STATUS_PENDING => 'bg-primary-subtle text-primary-emphasis',
    \Plugin\LongosProductReviews\Models\ReviewGenerationBatch::STATUS_RUNNING => 'bg-primary-subtle text-primary-emphasis',
    \Plugin\LongosProductReviews\Models\ReviewGenerationBatch::STATUS_COMPLETED => 'bg-success-subtle text-success-emphasis',
    \Plugin\LongosProductReviews\Models\ReviewGenerationBatch::STATUS_COMMITTED => 'bg-secondary-subtle text-secondary-emphasis',
    \Plugin\LongosProductReviews\Models\ReviewGenerationBatch::STATUS_FAILED => 'bg-danger-subtle text-danger-emphasis',
    \Plugin\LongosProductReviews\Models\ReviewGenerationBatch::STATUS_CANCELLED => 'bg-secondary-subtle text-secondary-emphasis',
  ];
@endphp

@if($generationBatches->isNotEmpty() || $showEmpty)
  <section class="card mb-4" aria-labelledby="review-generation-jobs-title">
    <div class="card-header">
      <h3 class="h6 mb-1" id="review-generation-jobs-title">{{ trans('LongosProductReviews::reviews.' . ($currentBatchId ? 'ai_task_history' : 'ai_tasks_title')) }}</h3>
      <p class="text-muted small mb-0">{{ trans('LongosProductReviews::reviews.ai_tasks_help') }}</p>
    </div>
    @if($generationBatches->isEmpty())
      <div class="card-body text-muted small"><i class="bi bi-inbox" aria-hidden="true"></i> {{ trans('LongosProductReviews::reviews.ai_tasks_empty') }}</div>
    @else
      <div class="list-group list-group-flush">
        @foreach($generationBatches as $generationBatch)
        @php
          $taskActive = in_array($generationBatch->status, [
            \Plugin\LongosProductReviews\Models\ReviewGenerationBatch::STATUS_PENDING,
            \Plugin\LongosProductReviews\Models\ReviewGenerationBatch::STATUS_RUNNING,
          ], true);
          $taskCompleted = $generationBatch->status === \Plugin\LongosProductReviews\Models\ReviewGenerationBatch::STATUS_COMPLETED;
          $taskCurrent = (string) $currentBatchId === (string) $generationBatch->id;
        @endphp
        <div class="list-group-item py-3" @if($watchTasks && $taskActive) data-generation-task data-status-url="{{ admin_route('longos_product_reviews.generate.status', ['batch' => $generationBatch->id]) }}" @endif>
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="flex-grow-1">
              <div class="d-flex flex-wrap align-items-center gap-2">
                <strong>{{ $generationBatch->product?->description?->name ?: '#' . $generationBatch->product_id }}</strong>
                <span class="badge {{ $statusClasses[$generationBatch->status] ?? 'bg-light text-dark' }}">{{ trans('LongosProductReviews::reviews.ai_task_status_' . $generationBatch->status) }}</span>
                @if($taskCurrent)<span class="badge bg-dark">{{ trans('LongosProductReviews::reviews.ai_task_current') }}</span>@endif
              </div>
              <div class="text-muted small mt-1">
                {{ trans('LongosProductReviews::reviews.ai_task_created_at', ['time' => $generationBatch->created_at?->diffForHumans()]) }}
                <span aria-hidden="true"> · </span>
                <span data-task-progress>{{ trans('LongosProductReviews::reviews.ai_batch_progress', ['processed' => $generationBatch->processed_count, 'total' => $generationBatch->requested_count]) }}</span>
              </div>
            </div>
            <a class="btn {{ $taskCompleted ? 'btn-success' : 'btn-outline-secondary' }}" href="{{ admin_route('longos_product_reviews.generate', ['batch' => $generationBatch->id]) }}">
              <i class="bi bi-{{ $taskCompleted ? 'eye' : 'arrow-right-circle' }}" aria-hidden="true"></i>
              {{ trans('LongosProductReviews::reviews.' . ($taskCompleted ? 'ai_task_view_results' : 'ai_task_open')) }}
            </a>
          </div>
        </div>
        @endforeach
      </div>
    @endif
    @if($generationBatches->contains('status', \Plugin\LongosProductReviews\Models\ReviewGenerationBatch::STATUS_COMPLETED))
      <div class="card-footer text-success-emphasis bg-success-subtle small"><i class="bi bi-info-circle" aria-hidden="true"></i> {{ trans('LongosProductReviews::reviews.ai_task_completed_notice') }}</div>
    @endif
  </section>
@endif
