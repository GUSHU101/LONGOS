<?php

namespace Plugin\PixelTracking\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Plugin\PixelTracking\Services\ServerTrackingService;

class SendTrackingEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public readonly string $encryptedPayload;

    public function __construct(array $prepared)
    {
        // Queue rows can live in a central database. Encrypt request context
        // so email, phone, IP, and advertising identifiers are never stored
        // there as readable serialized job data.
        $this->encryptedPayload = Crypt::encryptString(json_encode($prepared, JSON_THROW_ON_ERROR));
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ServerTrackingService $tracking): void
    {
        // Throwing on provider rejection lets Laravel retain and retry the
        // tenant-aware event instead of silently losing a conversion.
        $payload  = Crypt::decryptString($this->encryptedPayload);
        $prepared = json_decode($payload, true, 128, JSON_THROW_ON_ERROR);
        if (! is_array($prepared)) {
            throw new \RuntimeException('Pixel tracking queue payload is invalid.');
        }

        $tracking->trackPreparedBrowserEvent($prepared, true);
    }
}
