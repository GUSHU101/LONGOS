<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class SiliconFlowRateLimiter
{
    private const HEADROOM_PERCENT = 90;

    public function __construct(private readonly SiliconFlowRateLimitCatalog $catalog) {}

    public function awaitCapacity(string $apiKey, string $model, int $tokenEstimate): void
    {
        while (($wait = $this->reserveCapacity($apiKey, $model, $tokenEstimate)) > 0) {
            sleep(min(60, $wait));
        }
    }

    public function reserveCapacity(string $apiKey, string $model, int $tokenEstimate): int
    {
        return $this->reserve($apiKey, $model, $tokenEstimate);
    }

    public function penalize(string $apiKey, string $model, int $seconds): void
    {
        $key = $this->key($apiKey, $model);

        try {
            Cache::lock($key . ':lock', 10)->block(5, function () use ($key, $seconds): void {
                $state                   = $this->state($key);
                $state['cooldown_until'] = max((int) $state['cooldown_until'], time() + min(600, max(5, $seconds)));
                Cache::put($key, $state, now()->addMinutes(12));
            });
        } catch (LockTimeoutException) {
            // The next reservation retries under the same distributed lock.
        }
    }

    /** @return array{rpm:int,tpm:int} */
    public static function effectiveLimits(int $rpm, int $tpm): array
    {
        return [
            'rpm' => max(1, (int) floor($rpm * self::HEADROOM_PERCENT / 100)),
            'tpm' => max(1, (int) floor($tpm * self::HEADROOM_PERCENT / 100)),
        ];
    }

    private function reserve(string $apiKey, string $model, int $tokenEstimate): int
    {
        $profile = $this->catalog->profile($model);
        $limits  = self::effectiveLimits($profile['rpm'], $profile['tpm']);
        $rpm     = $limits['rpm'];
        $tpm     = $limits['tpm'];
        $tokens  = min($tpm, max(1, $tokenEstimate));
        $key     = $this->key($apiKey, $model);

        try {
            return Cache::lock($key . ':lock', 10)->block(5, function () use ($key, $rpm, $tpm, $tokens): int {
                $now   = time();
                $state = $this->state($key);
                if ((int) $state['cooldown_until'] > $now) {
                    return min(600, (int) $state['cooldown_until'] - $now);
                }
                if ((int) $state['window_started_at'] <= $now - 60) {
                    $state = [
                        'window_started_at' => $now,
                        'requests'          => 0,
                        'tokens'            => 0,
                        'cooldown_until'    => 0,
                    ];
                }
                if ((int) $state['requests'] >= $rpm || (int) $state['tokens'] + $tokens > $tpm) {
                    return max(1, min(60, (int) $state['window_started_at'] + 60 - $now));
                }
                $state['requests'] = (int) $state['requests'] + 1;
                $state['tokens']   = (int) $state['tokens']   + $tokens;
                Cache::put($key, $state, now()->addMinutes(12));

                return 0;
            });
        } catch (LockTimeoutException) {
            return 1;
        }
    }

    /** @return array{window_started_at:int,requests:int,tokens:int,cooldown_until:int} */
    private function state(string $key): array
    {
        $state = Cache::get($key, []);

        return [
            'window_started_at' => (int) ($state['window_started_at'] ?? time()),
            'requests'          => (int) ($state['requests'] ?? 0),
            'tokens'            => (int) ($state['tokens'] ?? 0),
            'cooldown_until'    => (int) ($state['cooldown_until'] ?? 0),
        ];
    }

    private function key(string $apiKey, string $model): string
    {
        return 'longos:siliconflow:rate:' . hash('sha256', trim($apiKey) . "\0" . trim($model));
    }
}
