<?php

namespace Plugin\LongosGlobalTranslate\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Plugin\LongosGlobalTranslate\Services\TranslationJobService;

final class ProcessTranslationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 90;

    public int $tries = 1;

    public function __construct(public readonly int $translationJobId) {}

    public function handle(TranslationJobService $service): void
    {
        // LONGOS queues are tenant-aware by default. The payload contains only
        // a tenant-local job ID; it never serializes source text or credentials.
        $service->process($this->translationJobId);
    }
}
