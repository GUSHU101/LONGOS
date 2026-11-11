<?php

namespace Plugin\LongosInstantTranslate\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Plugin\LongosInstantTranslate\Services\InstantTranslateSettings;
use Plugin\LongosInstantTranslate\Services\LibraryBuilder;
use Plugin\LongosInstantTranslate\Services\TranslationLibrary;

final class ProcessTranslationLibrary implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 45;

    public int $uniqueFor = 90;

    public function __construct(public readonly string $tenantScope)
    {
        $this->onQueue('translations');
    }

    public function uniqueId(): string
    {
        return 'longos-instant-translate:' . $this->tenantScope;
    }

    public function handle(LibraryBuilder $builder, TranslationLibrary $library, InstantTranslateSettings $settings): void
    {
        $builder->processOne();
        $delay = $library->nextPendingDelay($settings->buildIntervalSeconds());
        if ($settings->libraryBuildEnabled() && $delay !== null && config('queue.default') !== 'sync') {
            self::dispatch($this->tenantScope)->delay(now()->addSeconds($delay));
        }
    }
}
