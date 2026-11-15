<?php

namespace Plugin\LongosProductReviews\Controllers;

use Beike\Admin\Http\Controllers\Controller;
use Beike\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Plugin\LongosProductReviews\Jobs\GenerateReviewDrafts;
use Plugin\LongosProductReviews\Models\Review;
use Plugin\LongosProductReviews\Services\SiliconFlowReviewDrafts;
use RuntimeException;

final class AiReviewController extends Controller
{
    public function saveKey(Request $request, SiliconFlowReviewDrafts $generator): RedirectResponse
    {
        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'max:512'],
            'clear_key' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('clear_key')) {
            $generator->clearKey();

            return back()->with('success', trans('LongosProductReviews::reviews.ai_key_removed'));
        }

        $apiKey = trim((string) ($validated['api_key'] ?? ''));
        if ($apiKey === '') {
            throw ValidationException::withMessages(['api_key' => trans('LongosProductReviews::reviews.ai_key_required')]);
        }

        try {
            $generator->saveKey($apiKey);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['api_key' => trans('LongosProductReviews::reviews.ai_key_invalid')]);
        }

        return back()->with('success', trans('LongosProductReviews::reviews.ai_key_saved'));
    }

    public function generate(Request $request, SiliconFlowReviewDrafts $generator): RedirectResponse
    {
        if (! $generator->configured()) {
            throw ValidationException::withMessages(['api_key' => trans('LongosProductReviews::reviews.ai_key_required')]);
        }

        $data = $request->validate([
            'product_ids' => ['nullable', 'string', 'max:4000'],
            'limit' => ['required', 'integer', 'between:1,50'],
            'per_product' => ['required', 'integer', 'between:1,5'],
            'language' => ['required', 'string', Rule::in(array_keys(SiliconFlowReviewDrafts::LANGUAGES))],
            'skip_reviewed' => ['nullable', 'boolean'],
        ]);

        if (config('queue.default') === 'sync') {
            throw ValidationException::withMessages(['limit' => trans('LongosProductReviews::reviews.ai_async_required')]);
        }

        $scope = $this->scope();
        $input = trim((string) ($data['product_ids'] ?? ''));
        $ids = [];
        if ($input !== '') {
            $parts = preg_split('/[\s,，;；]+/u', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($parts) > 200 || array_filter($parts, static fn (string $id): bool => ! ctype_digit($id) || (int) $id < 1)) {
                throw ValidationException::withMessages(['product_ids' => trans('LongosProductReviews::reviews.ai_product_ids_invalid')]);
            }
            $ids = array_values(array_unique(array_map('intval', $parts)));
        }

        $products = Product::query()->where('active', true)->orderBy('id', 'desc');
        if ($ids !== []) {
            $products->whereIn('id', $ids);
        }
        if ($request->boolean('skip_reviewed')) {
            $products->whereNotIn('id', Review::query()->select('product_id')->where('published', true));
        }

        $productIds = $products->limit((int) $data['limit'])->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($productIds === []) {
            throw ValidationException::withMessages(['product_ids' => trans('LongosProductReviews::reviews.ai_products_empty')]);
        }

        $batchId = (string) Str::uuid();
        $key = GenerateReviewDrafts::cacheKey($scope, $batchId);
        Cache::put($key, [
            'status' => 'queued',
            'total' => count($productIds),
            'completed' => 0,
            'created' => 0,
            'failed' => 0,
            'recent_errors' => [],
            'started_at' => now()->toIso8601String(),
        ], now()->addDays(2));

        $jobs = array_map(
            static fn (int $productId): GenerateReviewDrafts => new GenerateReviewDrafts(
                $batchId, $productId, (int) $data['per_product'], (string) $data['language'], $scope,
            ),
            $productIds,
        );

        try {
            // Sequential chain: free-model calls do not fan out across workers.
            Bus::chain($jobs)->dispatch();
        } catch (\Throwable $exception) {
            Cache::forget($key);
            throw ValidationException::withMessages(['limit' => trans('LongosProductReviews::reviews.ai_queue_failed')]);
        }

        $request->session()->put('longos_product_reviews.ai_batch', $batchId);

        return redirect(admin_route('longos_product_reviews.index'))
            ->with('success', trans('LongosProductReviews::reviews.ai_batch_queued', ['count' => count($productIds)]));
    }

    public static function currentStatus(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $batchId = (string) $request->session()->get('longos_product_reviews.ai_batch', '');
        if (! Str::isUuid($batchId)) {
            return null;
        }

        $scope = (string) (current_site()?->id ?? 'central');
        $status = Cache::get(GenerateReviewDrafts::cacheKey($scope, $batchId));

        return is_array($status) ? $status : null;
    }

    private function scope(): string
    {
        return (string) (current_site()?->id ?? 'central');
    }
}
