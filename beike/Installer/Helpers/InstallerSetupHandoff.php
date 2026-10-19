<?php

namespace Beike\Installer\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Carries the secret installer payload safely across the .env transition.
 * The POST may use a different session backend from the redirected request.
 */
final class InstallerSetupHandoff
{
    public const COOKIE = 'longos_installer_handoff';

    private const LIFETIME_MINUTES = 120;

    /** @param array{admin_email: string, admin_password: string} $setup */
    public function queue(Request $request, array $setup, string $databaseSignature): void
    {
        $payload = json_encode([
            'admin_email'        => $setup['admin_email'],
            'admin_password'     => $setup['admin_password'],
            'database_signature' => $databaseSignature,
            'expires_at'         => time() + self::LIFETIME_MINUTES * 60,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        Cookie::queue(cookie(
            self::COOKIE,
            Crypt::encryptString($payload),
            self::LIFETIME_MINUTES,
            '/installer',
            null,
            is_secure(),
            true,
            false,
            'lax',
        ));
    }

    /** @return array{admin_email: string, admin_password: string}|null */
    public function recover(Request $request, string $databaseSignature): ?array
    {
        $encrypted = $request->cookie(self::COOKIE);
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($encrypted), true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($payload)
                || ! is_string($payload['database_signature'] ?? null)
                || ! hash_equals($databaseSignature, $payload['database_signature'])
                || ! is_int($payload['expires_at'] ?? null)
                || $payload['expires_at'] < time()
                || $payload['expires_at'] > time() + self::LIFETIME_MINUTES * 60 + 300
                || ! is_string($payload['admin_email'] ?? null)
                || filter_var($payload['admin_email'], FILTER_VALIDATE_EMAIL) === false
                || ! is_string($payload['admin_password'] ?? null)
                || strlen($payload['admin_password']) < 8
                || strlen($payload['admin_password']) > 128) {
                return null;
            }

            return [
                'admin_email'    => $payload['admin_email'],
                'admin_password' => $payload['admin_password'],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE, '/installer'));
    }
}
