<?php

namespace Plugin\LongosGlobalTranslate\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Plugin\LongosGlobalTranslate\Services\TranslationJobService;
use Plugin\LongosGlobalTranslate\Services\TranslationSchema;
use Throwable;

final class ProcessTranslationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public readonly string $runToken;

    public function __construct(public readonly int $translationJobId, ?string $runToken = null)
    {
        $this->runToken = $runToken ?: (string) Str::uuid();
        $this->onQueue((string) config('longos.translation.queue', 'translations'));
    }

    public function handle(TranslationJobService $service): void
    {
        // A worker can start while a rolling deployment is still repairing
        // the plugin schema. Failing closed here prevents any provider call
        // until the durable accounting and claim tables are available.
        if (! TranslationSchema::ready()) {
            return;
        }
        // LONGOS queues are tenant-aware by default. The payload contains only
        // a tenant-local job ID; it never serializes source text or credentials.
        $service->process(
            $this->translationJobId,
            (int) config('longos.translation.batch_size', 20),
            $this->runToken,
        );
    }

    public function failed(Throwable $exception): void
    {
        if (! TranslationSchema::ready()) {
            return;
        }

        // Laravel invokes this only after the configured retry policy is
        // exhausted. Marking our own claim failed lets the parent job finish
        // visibly without ever replaying an outcome that might have reached a
        // paid external provider.
        try {
            app(TranslationJobService::class)->failClaimsForRun($this->translationJobId, $this->runToken);
        } catch (Throwable $recoveryFailure) {
            // Do not let bookkeeping failure hide Laravel's original failed
            // job record. The claim remains visible for operator recovery.
            report($recoveryFailure);
        }
    }
}
