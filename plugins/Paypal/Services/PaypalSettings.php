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

        return self::decryptForRuntime(self::canonicalizeLegacySettings(
            is_array($settings) ? $settings : []
        ));
    }

    /**
     * Present every supported historical field name through the current
     * settings contract. Older installations used generic NVP credentials
     * and api_standard=soap; without this bridge the admin form could switch
     * a working account to REST and then fail validation on its next save.
     */
    public static function canonicalizeLegacySettings(array $settings): array
    {
        $environment = filter_var($settings['sandbox_mode'] ?? true, FILTER_VALIDATE_BOOLEAN)
            ? 'sandbox'
            : 'live';

        $settings['api_mode'] = self::normalizeApiMode(self::firstValue(
            $settings,
            ['api_mode', 'api_standard', 'paypal_api_mode'],
            'rest'
        ));

        foreach (['sandbox', 'live'] as $prefix) {
            self::fillAlias($settings, "{$prefix}_client_id", [
                "{$prefix}_client_id",
                "{$prefix}_clientId",
            ]);
            self::fillAlias($settings, "{$prefix}_secret", [
                "{$prefix}_secret",
                "{$prefix}_client_secret",
            ]);
            self::fillAlias($settings, "{$prefix}_merchant_id", [
                "{$prefix}_merchant_id",
                "merchant_{$prefix}_id",
            ]);

            foreach (['username', 'password', 'signature'] as $field) {
                self::fillAlias($settings, "{$prefix}_api_{$field}", [
                    "{$prefix}_api_{$field}",
                    "nvp_{$prefix}_{$field}",
                    "{$prefix}_nvp_{$field}",
                    "api_{$prefix}_{$field}",
                    "{$prefix}_{$field}",
                ]);
            }
        }

        // Generic credentials historically represented only the selected
        // environment. Never copy them into both environments.
        self::fillAlias($settings, "{$environment}_client_id", ['client_id', 'clientId']);
        self::fillAlias($settings, "{$environment}_secret", ['client_secret', 'secret']);
        self::fillAlias($settings, "{$environment}_merchant_id", ['merchant_id']);
        foreach (['username', 'password', 'signature'] as $field) {
            self::fillAlias($settings, "{$environment}_api_{$field}", [
                "nvp_{$field}",
                "api_{$field}",
                $field,
            ]);
        }

        return $settings;
    }

    /**
     * Password inputs are intentionally empty in the browser. Restore the
     * effective saved value before validation, including values that still
     * live under a legacy alias.
     */
    public static function preserveBlankSecrets(array $submitted, array $stored): array
    {
        $stored = self::canonicalizeLegacySettings($stored);

        foreach (['sandbox', 'live'] as $environment) {
            foreach (["{$environment}_secret", "{$environment}_api_password", "{$environment}_api_signature"] as $name) {
                if (! array_key_exists($name, $submitted) || trim((string) $submitted[$name]) !== '') {
                    continue;
                }

                $existing = $stored[$name] ?? '';
                if (is_scalar($existing) && trim((string) $existing) !== '') {
                    $submitted[$name] = (string) $existing;
                }
            }
        }

        return $submitted;
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

    public static function normalizeApiMode(string $mode): string
    {
        $mode = str_replace([' ', '-'], '_', strtolower(trim($mode)));

        return in_array($mode, ['nvp', 'soap', 'nvp_soap', 'nvp/soap'], true) ? 'nvp' : 'rest';
    }

    /** @param list<string> $aliases */
    private static function fillAlias(array &$settings, string $canonical, array $aliases): void
    {
        if (self::rawValue($settings, [$canonical]) !== null) {
            return;
        }

        $value = self::rawValue($settings, $aliases);
        if ($value !== null) {
            $settings[$canonical] = $value;
        }
    }

    /** @param list<string> $keys */
    private static function firstValue(array $settings, array $keys, string $default = ''): string
    {
        $value = self::rawValue($settings, $keys);

        return $value === null ? $default : trim((string) $value);
    }

    /** @param list<string> $keys */
    private static function rawValue(array $settings, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = $settings[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return $value;
            }
        }

        return null;
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
