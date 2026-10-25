<?php

namespace Plugin\Paypal\Services;

use Illuminate\Support\Facades\Crypt;

final class PaypalSettings
{
    public const ENCRYPTED_PREFIX = 'enc:v1:';

    private const SECRET_SUFFIXES = [
        '_secret',
        '_password',
        '_signature',
    ];

    private const SECRET_NAMES = [
        'secret',
        'password',
        'signature',
    ];

    public static function all(): array
    {
        $settings = plugin_setting('paypal', []);

        return self::decryptForRuntime(is_array($settings) ? $settings : []);
    }

    public static function normalizeForStorage(array $settings): array
    {
        foreach ($settings as $name => $value) {
            if (! self::isSecretField((string) $name) || ! is_scalar($value)) {
                continue;
            }

            $secret = trim((string) $value);
            if ($secret === '' || str_starts_with($secret, self::ENCRYPTED_PREFIX)) {
                $settings[$name] = $secret;

                continue;
            }

            $settings[$name] = self::ENCRYPTED_PREFIX . Crypt::encryptString($secret);
        }

        return $settings;
    }

    public static function decryptForRuntime(array $settings): array
    {
        foreach ($settings as $name => $value) {
            if (! self::isSecretField((string) $name) || ! is_scalar($value)) {
                continue;
            }

            $settings[$name] = self::decryptSecret((string) $value);
        }

        return $settings;
    }

    public static function hasStoredSecret(array $settings, string $name): bool
    {
        if (! self::isSecretField($name)) {
            return false;
        }

        return self::decryptSecret((string) ($settings[$name] ?? '')) !== '';
    }

    public static function isSecretField(string $name): bool
    {
        $name = strtolower($name);
        if (in_array($name, self::SECRET_NAMES, true)) {
            return true;
        }

        foreach (self::SECRET_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private static function decryptSecret(string $value): string
    {
        $value = trim($value);
        if ($value === '' || ! str_starts_with($value, self::ENCRYPTED_PREFIX)) {
            // Compatibility for installations that have not run the encryption
            // migration yet. The next settings save also upgrades these values.
            return $value;
        }

        try {
            return trim(Crypt::decryptString(substr($value, strlen(self::ENCRYPTED_PREFIX))));
        } catch (\Throwable) {
            // Never send corrupt or foreign-key ciphertext to PayPal as though
            // it were a real credential.
            return '';
        }
    }
}
