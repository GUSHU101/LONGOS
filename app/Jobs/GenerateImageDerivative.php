<?php

namespace App\Jobs;

use Beike\Services\ImageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateImageDerivative implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    /** @var array<int, int> */
    public array $backoff = [15, 60];

    public function __construct(
        public readonly string $image,
        public readonly int $width,
        public readonly int $height,
    ) {}

    public function uniqueId(): string
    {
        return hash('sha256', $this->image . '|' . $this->width . 'x' . $this->height);
    }

    public function handle(): void
    {
        (new ImageService($this->image))->resize($this->width, $this->height);
    }
}
