<?php

namespace Plugin\PixelTracking\Services;

use App\Saas\Support\TenantDeferred;
use Illuminate\Support\Facades\Log;
use Plugin\PixelTracking\Jobs\SendTrackingEvent;

final class PixelEventDispatcher
{
    public static function dispatch(array $prepared): void
    {
        $connection = (string) config('queue.default', 'sync');
        $driver     = (string) config("queue.connections.{$connection}.driver", 'sync');

        // A sync queue would put provider latency back on checkout. Keep the
        // existing post-response fallback for local/dev installs.
        if ($driver === 'sync') {
            self::defer($prepared);

            return;
        }

        try {
            SendTrackingEvent::dispatch($prepared)->afterCommit();
        } catch (\Throwable $exception) {
            Log::warning('Pixel tracking queue dispatch failed; using after-response fallback.', [
                'event_id'  => $prepared['event_id'] ?? '',
                'exception' => $exception::class,
            ]);
            self::defer($prepared);
        }
    }

    private static function defer(array $prepared): void
    {
        TenantDeferred::terminating(static function () use ($prepared): void {
            app(ServerTrackingService::class)->trackPreparedBrowserEvent($prepared);
        });
    }
}
