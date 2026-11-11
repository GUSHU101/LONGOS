<?php

namespace Plugin\LongosInstantTranslate\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Plugin\LongosInstantTranslate\Services\ContentScanner;
use Plugin\LongosInstantTranslate\Services\InstantTranslateSettings;
use Throwable;

final class ScanTranslationLibrary implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 1800;

    /** @param list<string> $targetLocales */
    public function __construct(
        public readonly string $tenantScope,
        public readonly array $targetLocales,
    ) {
        $this->onQueue('translations');
    }

    public static function statusKey(string $tenantScope): string
    {
        return 'longos-instant-translate:scan:' . $tenantScope;
    }

    public function uniqueId(): string
    {
        return 'longos-instant-translate-scan:' . $this->tenantScope;
    }

    public function handle(ContentScanner $scanner, InstantTranslateSettings $settings): void
    {
        Cache::put(self::statusKey($this->tenantScope), [
            'state' => 'running', 'started_at' => now()->toIso8601String(),
        ], now()->addHour());

        $report = $scanner->scan($this->targetLocales);
        Cache::put(self::statusKey($this->tenantScope), [
            'state'       => 'complete',
            'found'       => (int) $report['found'],
            'queued'      => (int) $report['queued'],
            'error_count' => count($report['errors']),
            'finished_at' => now()->toIso8601String(),
        ], now()->addHours(6));

        if ($settings->libraryBuildEnabled()) {
            ProcessTranslationLibrary::dispatch($this->tenantScope);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(self::statusKey($this->tenantScope), [
            'state' => 'failed', 'finished_at' => now()->toIso8601String(),
        ], now()->addHours(6));
    }
}
