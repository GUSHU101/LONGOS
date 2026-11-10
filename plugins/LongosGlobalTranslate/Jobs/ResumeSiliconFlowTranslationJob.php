<?php

namespace Plugin\LongosGlobalTranslate\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Plugin\LongosGlobalTranslate\Services\TranslationJobService;
use Plugin\LongosGlobalTranslate\Services\TranslationSchema;

/** Delayed and idempotent; no translation attempt is replayed by this job. */
final class ResumeSiliconFlowTranslationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $translationJobId, public readonly string $pauseNonce)
    {
        $this->onQueue((string) config('longos.translation.queue', 'translations'));
    }

    public function handle(TranslationJobService $service): void
    {
        if (TranslationSchema::ready()) {
            $service->resumeSiliconFlowRateWindow($this->translationJobId, $this->pauseNonce);
        }
    }
}
