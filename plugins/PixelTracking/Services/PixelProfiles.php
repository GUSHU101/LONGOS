<?php

namespace Plugin\PixelTracking\Services;

use Illuminate\Support\Facades\Crypt;

class PixelProfiles
{
    public const MAX_PROFILES_PER_CHANNEL = 10;

    private const ENCRYPTED_TOKEN_PREFIX = 'enc:v1:';

    public static function meta(): array
    {
        return self::profiles('meta_pixels', 'pixel_id');
    }

    public static function tiktok(): array
    {
        return self::profiles('tiktok_pixels', 'pixel_code');
    }

    /**
     * Validate a profile collection before it reaches persistent settings.
     *
     * @return string|null A user-facing validation error, or null when valid.
     */
    public static function validationError(mixed $value, string $channel): ?string
    {
        $items = is_array($value) ? $value : json_decode((string) $value, true);
        if (! is_array($items)) {
            return '配置必须是有效的 JSON 数组。';
        }
        if (! array_is_list($items)) {
            return '配置必须是 JSON 数组，不能使用对象键。';
        }
        if (count($items) > self::MAX_PROFILES_PER_CHANNEL) {
            return '每个渠道最多配置 ' . self::MAX_PROFILES_PER_CHANNEL . ' 个像素。';
        }

        $identifier = $channel === 'meta' ? 'pixel_id' : 'pixel_code';
        $seen       = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                return '第 ' . ($index + 1) . ' 项必须是对象。';
            }

            $id = trim((string) ($item[$identifier] ?? ''));
            if ($id === '') {
                return '第 ' . ($index + 1) . ' 项缺少像素标识。';
            }

            $valid = $channel                                === 'meta'
                ? preg_match('/^\d{5,32}$/', $id)            === 1
                : preg_match('/^[A-Za-z0-9_-]{5,80}$/', $id) === 1;
            if (! $valid) {
                return '第 ' . ($index + 1) . ' 项的像素标识格式不正确。';
            }

            $dedupeKey = strtolower($id);
            if (isset($seen[$dedupeKey])) {
                return '像素标识不能重复：' . $id;
            }
            $seen[$dedupeKey] = true;

            $token = trim((string) ($item['access_token'] ?? ''));
            if (str_starts_with($token, self::ENCRYPTED_TOKEN_PREFIX) && self::decryptToken($token) === '') {
                return '第 ' . ($index + 1) . ' 项的加密访问令牌已损坏，请重新填写。';
            }
            $maxTokenLength = str_starts_with($token, self::ENCRYPTED_TOKEN_PREFIX) ? 8192 : 4096;
            if (mb_strlen($token) > $maxTokenLength) {
                return '第 ' . ($index + 1) . ' 项的访问令牌过长。';
            }

            if ($channel === 'meta') {
                $version = trim((string) ($item['api_version'] ?? 'v26.0'));
                if (preg_match('/^v\d{1,2}\.\d$/', $version) !== 1) {
                    return '第 ' . ($index + 1) . ' 项的 Meta API 版本格式不正确，例如 v26.0。';
                }
            }
        }

        return null;
    }

    /**
     * Store only recognized, normalized keys so UI-only state and arbitrary
     * nested values cannot leak into the integration configuration.
     */
    public static function normalizeForStorage(mixed $value, string $channel): string
    {
        $items = is_array($value) ? $value : json_decode((string) $value, true);
        if (! is_array($items)) {
            return (string) $value;
        }

        $identifier = $channel === 'meta' ? 'pixel_id' : 'pixel_code';
        $normalized = [];

        foreach (array_slice($items, 0, self::MAX_PROFILES_PER_CHANNEL) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = trim((string) ($item[$identifier] ?? ''));
            if ($id === '') {
                continue;
            }

            $profile = [
                $identifier    => $id,
                'access_token' => self::encryptToken(trim((string) ($item['access_token'] ?? ''))),
            ];

            if ($channel === 'meta') {
                $profile['api_version']     = trim((string) ($item['api_version'] ?? 'v26.0')) ?: 'v26.0';
                $profile['test_event_code'] = mb_substr(trim((string) ($item['test_event_code'] ?? '')), 0, 120);
            }

            $normalized[] = $profile;
        }

        return (string) json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function profiles(string $setting, string $identifier): array
    {
        $value = plugin_setting("pixel_tracking.{$setting}", '[]');
        $items = is_array($value) ? $value : json_decode((string) $value, true);
        if (! is_array($items)) {
            return [];
        }

        $items = array_slice($items, 0, self::MAX_PROFILES_PER_CHANNEL);

        $seen = [];

        return array_values(array_filter(array_map(function ($item) use ($identifier, &$seen) {
            if (! is_array($item)) {
                return null;
            }

            $item[$identifier]    = trim((string) ($item[$identifier] ?? ''));
            $item['access_token'] = self::decryptToken(trim((string) ($item['access_token'] ?? '')));

            $channel = $identifier                                          === 'pixel_id' ? 'meta' : 'tiktok';
            $valid   = $channel                                             === 'meta'
                ? preg_match('/^\d{5,32}$/', $item[$identifier])            === 1
                : preg_match('/^[A-Za-z0-9_-]{5,80}$/', $item[$identifier]) === 1;
            $dedupeKey = strtolower($item[$identifier]);

            if (! $valid || isset($seen[$dedupeKey])) {
                return null;
            }
            $seen[$dedupeKey] = true;

            if ($channel === 'meta') {
                $version             = trim((string) ($item['api_version'] ?? 'v26.0'));
                $item['api_version'] = preg_match('/^v\d{1,2}\.\d$/', $version) === 1 ? $version : 'v26.0';
            }

            return $item;
        }, $items)));
    }

    private static function encryptToken(string $token): string
    {
        if ($token === '' || str_starts_with($token, self::ENCRYPTED_TOKEN_PREFIX)) {
            return $token;
        }

        return self::ENCRYPTED_TOKEN_PREFIX . Crypt::encryptString($token);
    }

    private static function decryptToken(string $token): string
    {
        if ($token === '' || ! str_starts_with($token, self::ENCRYPTED_TOKEN_PREFIX)) {
            // Backward compatibility: existing installations may still have
            // plaintext tokens until an administrator next saves the plugin.
            return $token;
        }

        try {
            return Crypt::decryptString(substr($token, strlen(self::ENCRYPTED_TOKEN_PREFIX)));
        } catch (\Throwable) {
            // Corrupt or foreign-key ciphertext must never be sent to an ad
            // provider as though it were a real credential.
            return '';
        }
    }
}
