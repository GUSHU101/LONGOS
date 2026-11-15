<?php

namespace Plugin\LongosProductReviews\Controllers;

use App\Services\BundledPluginSynchronizer;
use App\Services\SiliconFlowRateLimitCatalog;
use Beike\Admin\Http\Controllers\Controller;
use Beike\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Plugin\LongosProductReviews\Exceptions\GenerationBatchException;
use Plugin\LongosProductReviews\Exceptions\SiliconFlowException;
use Plugin\LongosProductReviews\Jobs\GenerateReviewBatch;
use Plugin\LongosProductReviews\Models\ReviewGenerationBatch;
use Plugin\LongosProductReviews\Requests\GenerateReviewsRequest;
use Plugin\LongosProductReviews\Requests\GenerationSettingsRequest;
use Plugin\LongosProductReviews\Services\ReviewGenerationBatchService;
use Plugin\LongosProductReviews\Services\ReviewService;
use Plugin\LongosProductReviews\Services\SiliconFlowClient;
use Plugin\LongosProductReviews\Services\SiliconFlowSettings;

class AdminReviewGenerationController extends Controller
{
    public function index(
        Request $request,
        SiliconFlowSettings $settings,
        ReviewGenerationBatchService $batches,
        ?SiliconFlowRateLimitCatalog $rateLimits = null,
    ): View {
        $this->ensureReady();
        $batches->recoverStale();
        $batches->pruneExpired();
        $requestedBatch = (string) $request->query('batch', '');
        $batch          = $requestedBatch !== ''
            ? $batches->findOwned($requestedBatch)
            : $batches->latestResumableOwned();
        $recentBatches = $batches->recentOwned();

        $publicSettings                        = $settings->public();
        $catalog                               = $rateLimits ?? app(SiliconFlowRateLimitCatalog::class);
        $publicSettings['rate_limit_profiles'] = array_map(
            fn (string $model): array => $catalog->profile($model),
            $publicSettings['models'],
        );

        return $this->page($request, $publicSettings, $batches, $batch, $recentBatches);
    }

    public function saveSettings(
        GenerationSettingsRequest $request,
        SiliconFlowSettings $settings,
        SiliconFlowRateLimitCatalog $rateLimits,
    ): RedirectResponse {
        $this->ensureReady();

        try {
            $settings->save($request->validated());
            $limits = array_map(
                fn (string $model): array => $rateLimits->profile($model, refresh: true),
                $settings->runtime()['models'],
            );
        } catch (SiliconFlowException $exception) {
            return back()->withErrors(['api_key' => $exception->getMessage()])->withInput($request->safe()->except('api_key'));
        }

        return redirect()->to(admin_route('longos_product_reviews.generate'))
            ->with('success', trans('LongosProductReviews::reviews.ai_settings_saved_limits', [
                'count'  => count($limits),
                'limits' => $this->formatLimits($limits),
            ]));
    }

    public function testConnection(SiliconFlowClient $client): RedirectResponse
    {
        $this->ensureReady();

        try {
            $models  = $client->availableChatModels();
            $missing = array_values(array_diff($client->configuredModels(), $models));
            if ($missing !== []) {
                throw new SiliconFlowException(trans('LongosProductReviews::reviews.ai_model_not_available', ['model' => implode(', ', $missing)]));
            }
            $limits = $client->configuredRateLimitProfiles(true);
        } catch (SiliconFlowException $exception) {
            return back()->withErrors(['connection' => $exception->getMessage()]);
        }

        return back()->with('success', trans('LongosProductReviews::reviews.ai_connection_success_limits', [
            'count' => count($limits), 'limits' => $this->formatLimits($limits),
        ]));
    }

    public function preview(
        GenerateReviewsRequest $request,
        ReviewGenerationBatchService $batches,
        SiliconFlowClient $client,
    ): RedirectResponse {
        $this->ensureReady();
        $batch = null;

        try {
            // Server-side configuration enforcement is required even though the
            // browser disables this button when no API key has been saved.
            $payload                      = $request->validated();
            $payload['_interface_locale'] = app()->getLocale();
            $payload['_models']           = $client->configuredModels();
            $batch                        = $batches->create($payload, $client->configuredModel());
            if ($batch->wasRecentlyCreated) {
                GenerateReviewBatch::dispatch($batch->id)->onQueue((string) config('longos.product_reviews.queue', 'product-reviews'));
            }
        } catch (SiliconFlowException|GenerationBatchException $exception) {
            return back()->withErrors(['generation' => $exception->getMessage()])->withInput();
        } catch (\Throwable $exception) {
            report($exception);
            if ($batch !== null) {
                $batches->fail($batch, trans('LongosProductReviews::reviews.ai_batch_dispatch_failed'));
            }

            return back()->withErrors(['generation' => trans('LongosProductReviews::reviews.ai_batch_dispatch_failed')])->withInput();
        }

        return redirect()->to(admin_route('longos_product_reviews.generate', ['batch' => $batch->id]))
            ->with('success', trans('LongosProductReviews::reviews.' . ($batch->wasRecentlyCreated
                ? 'ai_batch_queued'
                : 'ai_batch_existing')));
    }

    public function status(string $batch, ReviewGenerationBatchService $batches): JsonResponse
    {
        $this->ensureReady();
        $generationBatch = $batches->findOwned($batch);
        abort_if($generationBatch === null, 404);
        $generationBatch = $batches->recoverIfStale($generationBatch);

        return response()->json([
            'status'    => $generationBatch->status,
            'processed' => $generationBatch->processed_count,
            'total'     => $generationBatch->requested_count,
            'error'     => $generationBatch->status === ReviewGenerationBatch::STATUS_FAILED ? $generationBatch->error : null,
            'finished'  => $generationBatch->isFinished(),
        ]);
    }

    public function cancel(string $batch, ReviewGenerationBatchService $batches): RedirectResponse
    {
        $this->ensureReady();

        try {
            $cancelled = $batches->cancel($batch);
        } catch (GenerationBatchException $exception) {
            return redirect()->to(admin_route('longos_product_reviews.generate'))
                ->withErrors(['generation' => $exception->getMessage()]);
        }

        return redirect()->to(admin_route('longos_product_reviews.generate', ['batch' => $cancelled->id]))
            ->with('success', trans('LongosProductReviews::reviews.ai_batch_cancelled'));
    }

    public function commit(Request $request, ReviewGenerationBatchService $batches, ReviewService $reviews): RedirectResponse
    {
        $this->ensureReady();
        $validated = $request->validate([
            'batch_id'       => ['bail', 'required', 'uuid'],
            'selected_ids'   => ['bail', 'required', 'array', 'min:1', 'max:30'],
            'selected_ids.*' => ['bail', 'string', 'distinct', 'regex:/^[1-9][0-9]*$/'],
        ]);

        try {
            $created = $batches->commit($validated['batch_id'], $reviews, $validated['selected_ids']);
        } catch (GenerationBatchException $exception) {
            return redirect()->to(admin_route('longos_product_reviews.generate', ['batch' => $validated['batch_id']]))
                ->withErrors(['batch_id' => $exception->getMessage()]);
        }

        return redirect()->to(admin_route('longos_product_reviews.index', ['published' => '0', 'source' => 'generated']))
            ->with('success', trans('LongosProductReviews::reviews.ai_drafts_created', ['count' => $created->count()]));
    }

    /** @param array{configured:bool,model:string,models:list<string>,parallel_models:string,timeout:int,rate_limit_profiles?:list<array<string,mixed>>} $settings */
    private function page(
        Request $request,
        array $settings,
        ReviewGenerationBatchService $batches,
        ?ReviewGenerationBatch $batch = null,
        iterable $recentBatches = [],
    ): View {
        $batchInput = [];
        $preview    = null;
        if ($batch !== null) {
            try {
                $batchInput = $batches->requestData($batch);
                $preview    = $batch->status === ReviewGenerationBatch::STATUS_COMPLETED
                    ? $batches->result($batch)
                    : null;
            } catch (GenerationBatchException $exception) {
                $batches->fail($batch, $exception->getMessage());
                $batch->refresh();
            }
        }

        $productId = max(0, (int) $request->input('product_id', $request->query('product_id', 0)));
        if ($productId < 1) {
            $productId = (int) ($batchInput['product_id'] ?? $batch?->product_id ?? 0);
        }
        $selectedProduct  = $productId > 0 ? Product::query()->with('description')->find($productId) : null;
        $fallbackProducts = Product::query()->with('description')->where('active', true)->orderByDesc('updated_at')->limit(80)->get();
        if ($selectedProduct && ! $fallbackProducts->contains('id', $selectedProduct->id)) {
            $fallbackProducts->prepend($selectedProduct);
        }

        return view('LongosProductReviews::admin.generate', compact(
            'settings',
            'selectedProduct',
            'fallbackProducts',
            'batch',
            'batchInput',
            'preview',
            'recentBatches',
        ));
    }

    private function ensureReady(): void
    {
        app(BundledPluginSynchronizer::class)->ensureProductReviewsReady();
    }

    /** @param list<array{model:string,rpm:int,tpm:int}> $profiles */
    private function formatLimits(array $profiles): string
    {
        return implode('; ', array_map(
            static fn (array $profile): string => $profile['model'] . ': '
                . number_format($profile['rpm']) . ' RPM / '
                . number_format($profile['tpm']) . ' TPM',
            $profiles,
        ));
    }
}
