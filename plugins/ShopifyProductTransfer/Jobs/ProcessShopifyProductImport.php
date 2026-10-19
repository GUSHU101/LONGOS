<?php

namespace Plugin\ShopifyProductTransfer\Jobs;

use Beike\Services\QueueService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Plugin\ShopifyProductTransfer\Models\ShopifyProductImport;
use Plugin\ShopifyProductTransfer\Services\ShopifyCsvService;
use RuntimeException;
use Throwable;

class ProcessShopifyProductImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT_SECONDS = 3300;

    public int $tries = 1;

    public int $timeout = self::TIMEOUT_SECONDS;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $importId) {}

    public function handle(ShopifyCsvService $service): void
    {
        if (! Schema::hasTable('shopify_product_imports')) {
            $this->deletePrivateImportFile($this->defaultFilePath());

            return;
        }

        $import = ShopifyProductImport::query()->find($this->importId);
        if (! $import) {
            $this->deletePrivateImportFile($this->defaultFilePath());

            return;
        }
        if (in_array($import->status, [ShopifyProductImport::STATUS_COMPLETED, ShopifyProductImport::STATUS_FAILED], true)) {
            $this->deletePrivateImportFile($import->file_path);

            return;
        }

        $importPath = $this->privateImportPath($import->file_path);
        if ($importPath === null) {
            $this->markFailed($import, trans('ShopifyProductTransfer::transfer.import_file_missing'));

            return;
        }

        $path = Storage::disk('local')->path($importPath);
        if (! is_file($path)) {
            $this->markFailed($import, trans('ShopifyProductTransfer::transfer.import_file_missing'));

            return;
        }

        // Queue transports provide at-least-once delivery. Atomically lease a
        // pending run so two workers can never import the same private CSV at
        // the same time. A duplicate that observes RUNNING must leave the file
        // in place because the worker that owns the lease still needs it.
        $claimed = ShopifyProductImport::query()
            ->whereKey($import->getKey())
            ->where('status', ShopifyProductImport::STATUS_PENDING)
            ->update([
                'status'     => ShopifyProductImport::STATUS_RUNNING,
                'started_at' => now(),
                'error'      => null,
                'updated_at' => now(),
            ]);
        if ($claimed !== 1) {
            return;
        }
        $import->refresh();
        $this->pulseWorkerLease();

        $lastProgressAt = 0.0;

        try {
            $result = $service->import(
                $path,
                $import->replace_existing,
                function (int $processed, int $total, array $partial) use ($import, &$lastProgressAt): void {
                    $this->pulseWorkerLease();
                    $now = microtime(true);
                    if ($processed < $total && ($now - $lastProgressAt) < 1.0) {
                        return;
                    }
                    $lastProgressAt = $now;
                    $import->update([
                        'processed' => $processed,
                        'total'     => $total,
                        'result'    => $partial,
                    ]);
                },
            );

            $import->update([
                'status'      => ShopifyProductImport::STATUS_COMPLETED,
                'processed'   => max($import->processed, $import->total),
                'result'      => $result,
                'error'       => null,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $message = $exception instanceof InvalidArgumentException || $exception instanceof RuntimeException
                ? Str::limit($exception->getMessage(), 2000, '')
                : trans('ShopifyProductTransfer::transfer.import_failed');
            $this->markFailed($import, $message);
        } finally {
            $this->deletePrivateImportFile($import->file_path);
            $this->releaseWorkerLease();
        }
    }

    public function failed(?Throwable $exception): void
    {
        if (! Schema::hasTable('shopify_product_imports')) {
            $this->deletePrivateImportFile($this->defaultFilePath());

            return;
        }

        $import = ShopifyProductImport::query()->find($this->importId);
        if (! $import || $import->status === ShopifyProductImport::STATUS_COMPLETED) {
            $this->deletePrivateImportFile($this->defaultFilePath());

            return;
        }

        $this->markFailed($import, trans('ShopifyProductTransfer::transfer.import_worker_failed'));
        $this->deletePrivateImportFile($import->file_path);
        if ($exception) {
            report($exception);
        }
    }

    private function markFailed(ShopifyProductImport $import, string $message): void
    {
        $import->update([
            'status'      => ShopifyProductImport::STATUS_FAILED,
            'error'       => $message,
            'finished_at' => now(),
        ]);
    }

    private function defaultFilePath(): string
    {
        return 'shopify-imports/' . $this->importId . '.csv';
    }

    private function deletePrivateImportFile(mixed $path): void
    {
        $path = $this->privateImportPath($path);
        if ($path === null) {
            return;
        }

        try {
            Storage::disk('local')->delete($path);
        } catch (Throwable $exception) {
            report($exception);
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

    private function pulseWorkerLease(): void
    {
        if ($queue = $this->workerQueue()) {
            QueueService::recordWorkerHeartbeat($queue, self::TIMEOUT_SECONDS + 60);
        }
    }

    private function releaseWorkerLease(): void
    {
        if ($queue = $this->workerQueue()) {
            QueueService::recordWorkerHeartbeat($queue, 0, true);
        }
    }

    private function workerQueue(): ?string
    {
        // Direct after-response recovery calls handle() without a Laravel job
        // instance. It must not advertise a queue worker that does not exist.
        if ($this->job === null) {
            return null;
        }

        try {
            $queue = trim((string) $this->job->getQueue());
        } catch (Throwable) {
            $queue = trim((string) ($this->queue ?? ''));
        }

        if ($queue === '') {
            $connection = (string) config('queue.default', 'database');
            $queue      = trim((string) config("queue.connections.{$connection}.queue", 'default'));
        }

        return preg_match('/^[A-Za-z0-9_.:-]+$/', $queue) === 1 ? $queue : null;
    }
}
