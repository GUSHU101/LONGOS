<?php

namespace Plugin\ShopifyProductTransfer\Controllers;

use App\Saas\Support\TenantDeferred;
use Beike\Admin\Http\Controllers\Controller;
use Beike\Services\QueueService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Plugin\ShopifyProductTransfer\Jobs\ProcessShopifyProductImport;
use Plugin\ShopifyProductTransfer\Models\ShopifyProductImport;
use Plugin\ShopifyProductTransfer\Services\ShopifyCsvService;
use Plugin\ShopifyProductTransfer\Services\ShopifyTransferReadiness;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ShopifyProductController extends Controller
{
    private const CLEANUP_BATCH_SIZE = 100;

    public function index(ShopifyTransferReadiness $readiness)
    {
        $schemaError             = null;
        $importRuns              = new Collection;
        $recoverableImportId     = null;
        $maximumUploadKilobytes  = $this->maximumUploadKilobytes();
        $maximumUploadMegabytes  = $this->formatMegabytes($maximumUploadKilobytes);

        try {
            $readiness->ensureReady();
            $importRuns = $this->recentImports();
            if (! $this->queueWorkerAvailable() && Gate::allows('products_create')) {
                $recoverableImportId = $importRuns
                    ->first(fn (ShopifyProductImport $run): bool => $this->isRecoverableImport($run))
                    ?->id;
            }
        } catch (Throwable $exception) {
            report($exception);
            $schemaError = trans('ShopifyProductTransfer::transfer.database_unavailable');
        }

        return view('ShopifyProductTransfer::admin.index', [
            'maximumUploadKilobytes' => $maximumUploadKilobytes,
            'maximumUploadMegabytes' => $maximumUploadMegabytes,
            'fileTooLargeMessage'    => trans('ShopifyProductTransfer::transfer.file_too_large', [
                'size' => $maximumUploadMegabytes,
            ]),
            'importRuns'             => $importRuns,
            'recoverableImportId'    => $recoverableImportId,
            'schemaError'            => $schemaError,
        ]);
    }

    /**
     * A refreshed form submission becomes a GET request. Keep that recovery
     * path inside the plugin instead of exposing a generic 405/500 page.
     */
    public function importForm(): RedirectResponse
    {
        return $this->redirectToIndex();
    }

    public function import(
        Request $request,
        ShopifyCsvService $service,
        ShopifyTransferReadiness $readiness
    ): RedirectResponse {
        try {
            $readiness->ensureReady();
        } catch (Throwable $exception) {
            report($exception);

            return $this->redirectToIndex()
                ->with('warning', trans('ShopifyProductTransfer::transfer.database_unavailable'));
        }

        $validator = Validator::make($request->all(), [
            // Browser MIME detection for Shopify exports differs between
            // operating systems. The importer validates CSV headers itself,
            // so avoid rejecting a valid UTF-8 export solely by its MIME label.
            'file' => ['required', 'file', 'max:' . $this->maximumUploadKilobytes()],
        ]);
        if ($validator->fails()) {
            return $this->redirectToIndex()
                ->withInput($request->only('replace_existing'))
                ->withErrors($validator);
        }

        if ($request->boolean('replace_existing')) {
            Gate::authorize('products_update');
        }

        if ($this->queuedImportConfigured()) {
            $queued = $this->queueImport($request, $this->queueWorkerAvailable());
            if ($queued) {
                return $queued;
            }
        }

        // Shopify exports can contain remote images. Give the synchronous web
        // workflow enough PHP execution time on constrained deployments;
        // web-server timeouts still remain an operator-controlled hard limit.
        @set_time_limit(300);

        try {
            $result = $service->import($request->file('file'), $request->boolean('replace_existing'));
        } catch (\Throwable $exception) {
            report($exception);
            $message = $exception instanceof \InvalidArgumentException || $exception instanceof \RuntimeException
                ? $exception->getMessage()
                : trans('ShopifyProductTransfer::transfer.import_failed');

            return $this->redirectToIndex()
                ->withInput($request->only('replace_existing'))
                ->with('warning', $message);
        }
        // Never pass the full result to the translator. It also contains the
        // array-valued `errors` entry, which Laravel cannot use as a string
        // replacement and previously turned every completed import into a 500.
        $message = trans('ShopifyProductTransfer::transfer.import_result', $this->summaryReplacements($result));

        return $this->redirectToIndex()
            ->with(($result['failed'] || $result['warnings']) ? 'warning' : 'success', $message)
            ->with('shopify_import_errors', array_merge(
                $result['errors']          ?? [],
                $result['warning_details'] ?? [],
            ));
    }

    public function processPending(
        Request $request,
        string $importId,
        ShopifyCsvService $service,
        ShopifyTransferReadiness $readiness
    ): JsonResponse|RedirectResponse {
        try {
            $readiness->ensureReady();
        } catch (Throwable $exception) {
            report($exception);

            return $this->pendingResponse($request, null, trans('ShopifyProductTransfer::transfer.database_unavailable'), 503);
        }

        if (! Str::isUuid($importId)) {
            return $this->pendingResponse($request, null, trans('ShopifyProductTransfer::transfer.import_file_missing'), 404);
        }
        $import = ShopifyProductImport::query()
            ->whereKey($importId)
            ->where('admin_user_id', current_user()?->id)
            ->first();
        if (! $import) {
            return $this->pendingResponse($request, null, trans('ShopifyProductTransfer::transfer.import_file_missing'), 404);
        }
        if ($import->replace_existing) {
            Gate::authorize('products_update');
        }

        $recoveryCutoff = now()->subSeconds($this->recoveryLeaseSeconds());
        if ($import->status === ShopifyProductImport::STATUS_RUNNING
            && $import->updated_at?->lt($recoveryCutoff)) {
            ShopifyProductImport::query()
                ->whereKey($import->id)
                ->where('status', ShopifyProductImport::STATUS_RUNNING)
                ->where('updated_at', '<', $recoveryCutoff)
                ->update([
                    'status'     => ShopifyProductImport::STATUS_PENDING,
                    'processed'  => 0,
                    'total'      => 0,
                    'result'     => null,
                    'error'      => null,
                    'started_at' => null,
                    'updated_at' => now(),
                ]);
            $import->refresh();
        }

        $scheduled = false;
        if ($import->status === ShopifyProductImport::STATUS_PENDING) {
            $scheduled = true;
            TenantDeferred::terminating(static function () use ($import, $service): void {
                ignore_user_abort(true);
                @set_time_limit(ProcessShopifyProductImport::TIMEOUT_SECONDS);
                (new ProcessShopifyProductImport($import->id))->handle($service);
            });
        }

        return $this->pendingResponse($request, $import, status: $scheduled ? 202 : 200, accepted: $scheduled);
    }

    public function export(
        Request $request,
        ShopifyCsvService $service,
        ShopifyTransferReadiness $readiness
    ): StreamedResponse|RedirectResponse {
        if ($response = $this->ensureReadyOrRedirect($readiness)) {
            return $response;
        }

        @set_time_limit(300);

        return $service->export($this->exportIds((string) $request->query('ids', '')));
    }

    public function template(ShopifyCsvService $service): StreamedResponse
    {
        return $service->template();
    }

    public function exportSelected(
        Request $request,
        ShopifyCsvService $service,
        ShopifyTransferReadiness $readiness
    ): StreamedResponse|RedirectResponse {
        if ($response = $this->ensureReadyOrRedirect($readiness)) {
            return $response;
        }

        $request->validate(['ids' => ['required', 'string', 'max:30000']]);
        $ids = $this->exportIds((string) $request->input('ids'));
        if ($ids === []) {
            throw ValidationException::withMessages([
                'ids' => trans('ShopifyProductTransfer::transfer.select_products'),
            ]);
        }

        @set_time_limit(300);

        return $service->export($ids);
    }

    /** @return array<int, int> */
    private function exportIds(string $value): array
    {
        return collect(explode(',', $value))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->take(2000)
            ->values()
            ->all();
    }

    /** @return array<string, int> */
    private function summaryReplacements(array $result): array
    {
        $summary = [];
        foreach (['created', 'updated', 'skipped', 'failed', 'warnings', 'adjusted_skus'] as $key) {
            $summary[$key] = max(0, (int) ($result[$key] ?? 0));
        }

        return $summary;
    }

    private function redirectToIndex(): RedirectResponse
    {
        return redirect()->to(admin_route('shopify_product_transfer.index'));
    }

    private function ensureReadyOrRedirect(ShopifyTransferReadiness $readiness): ?RedirectResponse
    {
        try {
            $readiness->ensureReady();

            return null;
        } catch (Throwable $exception) {
            report($exception);

            return $this->redirectToIndex()
                ->with('warning', trans('ShopifyProductTransfer::transfer.database_unavailable'));
        }
    }

    private function queuedImportConfigured(): bool
    {
        if (! Schema::hasTable('shopify_product_imports')) {
            return false;
        }

        $connection = (string) config('queue.default', 'sync');
        $driver     = (string) config("queue.connections.{$connection}.driver", $connection);

        return ! in_array($driver, ['sync', 'null'], true);
    }

    private function queueWorkerAvailable(): bool
    {
        if (! $this->queuedImportConfigured()) {
            return false;
        }

        $connection = (string) config('queue.default', 'sync');
        $queue      = (string) config("queue.connections.{$connection}.queue", 'default');

        return QueueService::isQueueWorkerRunning($queue);
    }

    private function isRecoverableImport(ShopifyProductImport $import): bool
    {
        return $import->status === ShopifyProductImport::STATUS_PENDING
            || ($import->status === ShopifyProductImport::STATUS_RUNNING
                && $import->updated_at?->lt(now()->subSeconds($this->recoveryLeaseSeconds())));
    }

    private function recoveryLeaseSeconds(): int
    {
        $connection = (string) config('queue.default', 'database');
        $configured = config("queue.connections.{$connection}.retry_after", 0);
        $retryAfter = filter_var($configured, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        // Recovery must start after both Laravel's reservation lease and the
        // plugin's maximum execution time. The extra margin prevents a slow
        // image download near either boundary from creating a second worker.
        return max(
            ProcessShopifyProductImport::TIMEOUT_SECONDS + 600,
            ($retryAfter === false ? 0 : $retryAfter)    + 300,
        );
    }

    private function queueImport(Request $request, bool $dispatch): ?RedirectResponse
    {
        $file = $request->file('file');
        if (! $file) {
            return null;
        }

        $id       = (string) Str::uuid();
        $filePath = null;
        $import   = null;

        try {
            $filePath = Storage::disk('local')->putFileAs('shopify-imports', $file, $id . '.csv');
            if (! is_string($filePath) || $filePath === '') {
                return null;
            }
            $originalName = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));
            $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?: 'shopify.csv';
            $import       = ShopifyProductImport::query()->create([
                'id'               => $id,
                'admin_user_id'    => current_user()?->id,
                'original_name'    => Str::limit($originalName, 255, ''),
                'file_path'        => $filePath,
                'replace_existing' => $request->boolean('replace_existing'),
                'status'           => ShopifyProductImport::STATUS_PENDING,
            ]);
            if ($dispatch) {
                ProcessShopifyProductImport::dispatch($id);
            } else {
                // Finish the redirect first, then process within the captured
                // tenant. This avoids both a permanently pending database job
                // and a long browser request that Nginx/PHP-FPM may cut off.
                TenantDeferred::terminating(static function () use ($id): void {
                    ignore_user_abort(true);
                    @set_time_limit(3300);
                    (new ProcessShopifyProductImport($id))->handle(app(ShopifyCsvService::class));
                });
            }

            return $this->redirectToIndex()->with('success', trans(
                $dispatch
                    ? 'ShopifyProductTransfer::transfer.import_queued'
                    : 'ShopifyProductTransfer::transfer.import_local_queued'
            ));
        } catch (Throwable $exception) {
            // A newly installed site can briefly have a queue configured
            // before its jobs table/worker is ready. Clean up the queued copy
            // and safely fall back to the proven synchronous path.
            report($exception);

            try {
                $import?->delete();
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }
            $this->deletePrivateImportFile($filePath);

            return null;
        }
    }

    /** @return Collection<int, ShopifyProductImport> */
    private function recentImports(): Collection
    {
        if (! Schema::hasTable('shopify_product_imports')) {
            return new Collection;
        }

        // An interrupted deployment or missing worker must not retain private
        // uploads forever. A 24-hour lease is far beyond the job timeout and
        // still leaves a clear failed record for the administrator.
        $stale = ShopifyProductImport::query()
            ->whereIn('status', [ShopifyProductImport::STATUS_PENDING, ShopifyProductImport::STATUS_RUNNING])
            ->where('updated_at', '<', now()->subDay())
            ->oldest('updated_at')
            ->limit(self::CLEANUP_BATCH_SIZE)
            ->get();
        foreach ($stale as $import) {
            $this->deletePrivateImportFile($import->file_path);
            $import->update([
                'status'      => ShopifyProductImport::STATUS_FAILED,
                'error'       => trans('ShopifyProductTransfer::transfer.import_worker_failed'),
                'finished_at' => now(),
            ]);
        }
        $expired = ShopifyProductImport::query()
            ->whereIn('status', [ShopifyProductImport::STATUS_COMPLETED, ShopifyProductImport::STATUS_FAILED])
            ->where('finished_at', '<', now()->subDays(30))
            ->oldest('finished_at')
            ->limit(self::CLEANUP_BATCH_SIZE)
            ->get();
        foreach ($expired as $import) {
            if ($this->deletePrivateImportFile($import->file_path)) {
                $import->delete();
            }
        }

        return ShopifyProductImport::query()
            ->where('admin_user_id', current_user()?->id)
            ->latest()
            ->limit(10)
            ->get();
    }

    private function deletePrivateImportFile(mixed $path): bool
    {
        $path = $this->privateImportPath($path);
        if ($path === null) {
            return true;
        }

        try {
            return Storage::disk('local')->delete($path);
        } catch (Throwable $exception) {
            // A cleanup failure must not make the import page unavailable.
            report($exception);

            return false;
        }
    }

    private function privateImportPath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = trim(str_replace('\\', '/', $path));

        return preg_match('#^shopify-imports/[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.csv$#i', $path)
            ? $path
            : null;
    }

    private function pendingResponse(
        Request $request,
        ?ShopifyProductImport $import,
        ?string $error = null,
        int $status = 200,
        bool $accepted = false,
    ): JsonResponse|RedirectResponse {
        if (! $request->expectsJson()) {
            $message = $error ?: trans($accepted
                ? 'ShopifyProductTransfer::transfer.import_recovery_started'
                : 'ShopifyProductTransfer::transfer.import_recovered');

            return $this->redirectToIndex()->with($error ? 'warning' : 'success', $message);
        }

        return response()->json([
            'status'   => $import?->status,
            'accepted' => $accepted,
            'error'    => $error ?: $import?->error,
        ], $status);
    }

    private function maximumUploadKilobytes(): int
    {
        $applicationLimit = 25 * 1024;
        $limits           = array_filter([
            $this->iniBytes((string) ini_get('upload_max_filesize')),
            $this->iniBytes((string) ini_get('post_max_size')),
        ], static fn (int $bytes): bool => $bytes > 0 && $bytes < PHP_INT_MAX);

        if ($limits === []) {
            return $applicationLimit;
        }

        return max(1, min($applicationLimit, (int) floor(min($limits) / 1024)));
    }

    private function formatMegabytes(int $kilobytes): string
    {
        return rtrim(rtrim(number_format($kilobytes / 1024, 1, '.', ''), '0'), '.');
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }
        if (! preg_match('/^(\d+(?:\.\d+)?)\s*([kmgt]?)b?$/i', $value, $matches)) {
            return 0;
        }

        $bytes = (float) $matches[1];
        $power = array_search(strtolower($matches[2] ?? ''), ['', 'k', 'm', 'g', 't'], true);
        if ($power === false) {
            return 0;
        }

        return (int) min(PHP_INT_MAX, $bytes * (1024 ** $power));
    }
}
