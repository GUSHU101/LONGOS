<?php

namespace Plugin\PixelTracking\Services;

use Illuminate\Support\Facades\Cache;

final class TrackingHealth
{
    public static function record(
        string $channel,
        string $profileId,
        string $event,
        string $eventId,
        bool $success,
        int $httpStatus,
        ?string $message = null,
    ): void {
        try {
            $key = self::key($channel, $profileId);
            Cache::lock($key . ':write', 5)->block(2, function () use ($key, $channel, $profileId, $event, $eventId, $success, $httpStatus, $message): void {
                $previous     = Cache::get($key);
                $previous     = is_array($previous) ? $previous : [];
                $now          = now()->toIso8601String();
                $successCount = max(0, (int) ($previous['success_count'] ?? 0)) + ($success ? 1 : 0);
                $failureCount = max(0, (int) ($previous['failure_count'] ?? 0)) + ($success ? 0 : 1);

                Cache::put($key, [
                    'channel'              => $channel,
                    'profile_id'           => $profileId,
                    'event'                => $event,
                    'event_id'             => $eventId,
                    'success'              => $success,
                    'http_status'          => $httpStatus,
                    'message'              => self::safeMessage($message),
                    'recorded_at'          => $now,
                    'success_count'        => $successCount,
                    'failure_count'        => $failureCount,
                    'consecutive_failures' => $success ? 0 : max(0, (int) ($previous['consecutive_failures'] ?? 0)) + 1,
                    'last_success_at'      => $success ? $now : ($previous['last_success_at'] ?? null),
                    'last_failure_at'      => $success ? ($previous['last_failure_at'] ?? null) : $now,
                ], now()->addDays(7));
            });
        } catch (\Throwable) {
            // Tracking and checkout must not depend on the cache backend.
        }
    }

    public static function forProfiles(array $metaProfiles, array $tiktokProfiles, array $snapchatProfiles = []): array
    {
        $result = [];
        foreach ([
            'meta'     => [$metaProfiles, 'pixel_id'],
            'tiktok'   => [$tiktokProfiles, 'pixel_code'],
            'snapchat' => [$snapchatProfiles, 'pixel_id'],
        ] as $channel => [$profiles, $identifier]) {
            foreach ($profiles as $profile) {
                $profileId = (string) ($profile[$identifier] ?? '');
                if ($profileId === '') {
                    continue;
                }

                try {
                    $health = Cache::get(self::key($channel, $profileId));
                } catch (\Throwable) {
                    $health = null;
                }

                $result[$channel . ':' . $profileId] = is_array($health) ? $health : null;
            }
        }

        return $result;
    }

    private static function key(string $channel, string $profileId): string
    {
        return 'pixel_tracking:health:' . $channel . ':' . hash('sha256', $profileId);
    }

    private static function safeMessage(?string $message): ?string
    {
        $message = trim(strip_tags((string) $message));
        if ($message === '') {
            return null;
        }

        // Provider errors sometimes echo request values. Keep diagnostics
        // useful while stripping common token/query-secret forms.
        $message = preg_replace(
            '/(access[_-]?token|token|secret|authorization)["\']?\s*[=:]\s*["\']?[^"\'\s,&}]+/i',
            '$1=[redacted]',
            $message,
        );

        return mb_substr((string) $message, 0, 500);
    }
}
