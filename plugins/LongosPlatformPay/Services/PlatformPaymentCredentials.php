<?php

namespace Plugin\LongosPlatformPay\Services;

use Beike\Models\Setting;
use Illuminate\Support\Facades\Crypt;

final class PlatformPaymentCredentials
{
    public const ENCRYPTED_PREFIX = 'enc:v1:';

    private const SECRET_FIELDS = [
        'paypal_secret',
        'stripe_secret_key',
        'stripe_webhook_secret',
    ];

    /** @return array<string, mixed> */
    public static function all(): array
    {
        $settings = Setting::query()
            ->where('type', 'plugin')
            ->where('space', 'longos_platform_pay')
            ->get(['name', 'value', 'json'])
            ->mapWithKeys(static function (Setting $setting): array {
                $value = $setting->json ? json_decode((string) $setting->value, true) : $setting->value;

                return [(string) $setting->name => $value];
            })
            ->all();

        return self::decryptForRuntime($settings);
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    public static function preserveBlankSecrets(array $settings): array
    {
        $current = self::all();
        foreach (self::SECRET_FIELDS as $name) {
            if (! array_key_exists($name, $settings)
                || ! is_scalar($settings[$name])
                || trim((string) $settings[$name]) !== '') {
                continue;
            }

            $existing = trim((string) ($current[$name] ?? ''));
            if ($existing !== '') {
                $settings[$name] = $existing;
            }
        }

        return $settings;
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    public static function normalizeForStorage(array $settings): array
    {
        foreach (self::SECRET_FIELDS as $name) {
            if (! array_key_exists($name, $settings) || ! is_scalar($settings[$name])) {
                continue;
            }

            $secret = trim((string) $settings[$name]);
            if ($secret === '' || str_starts_with($secret, self::ENCRYPTED_PREFIX)) {
                $settings[$name] = $secret;

                continue;
            }

            $settings[$name] = self::ENCRYPTED_PREFIX . Crypt::encryptString($secret);
        }

        return $settings;
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    public static function decryptForRuntime(array $settings): array
    {
        foreach (self::SECRET_FIELDS as $name) {
            if (array_key_exists($name, $settings)) {
                $settings[$name] = self::runtimeValue($name, $settings[$name]);
            }
        }

        return $settings;
    }

    public static function runtimeValue(string $name, mixed $value): string
    {
        if (! in_array($name, self::SECRET_FIELDS, true) || ! is_scalar($value)) {
            return is_scalar($value) ? trim((string) $value) : '';
        }

        $value = trim((string) $value);
        if ($value === '' || ! str_starts_with($value, self::ENCRYPTED_PREFIX)) {
            return $value;
        }

        try {
            return trim(Crypt::decryptString(substr($value, strlen(self::ENCRYPTED_PREFIX))));
        } catch (\Throwable) {
            return '';
        }
    }
}
