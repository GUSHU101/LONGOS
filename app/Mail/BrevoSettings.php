<?php

namespace App\Mail;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Keeps Brevo credentials encrypted in tenant settings and exposes only
 * decrypted runtime values to the mail transport.
 */
class BrevoSettings
{
    private const ENCRYPTED_PREFIX = 'encrypted:';

    /** @return array{api_key:string,timeout:int,webhook_token:string} */
    public function runtime(): array
    {
        $settings = system_setting('base.brevo', []);
        $settings = is_array($settings) ? $settings : [];

        return [
            'api_key'       => $this->decrypt((string) ($settings['api_key'] ?? '')),
            'timeout'       => max(3, min(60, (int) ($settings['timeout'] ?? 15))),
            'webhook_token' => $this->decrypt((string) ($settings['webhook_token'] ?? '')),
        ];
    }

    /**
     * Blank secret inputs preserve the existing values. A webhook bearer
     * token is generated once and never rendered back into the admin page.
     *
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $existing
     * @return array{api_key:string,timeout:int,webhook_token:string}
     */
    public function prepareForStorage(array $incoming, array $existing = []): array
    {
        $apiKey = trim((string) ($incoming['api_key'] ?? ''));
        if ($apiKey === '') {
            $apiKey = $this->decrypt((string) ($existing['api_key'] ?? ''));
        }
        $apiKey = $this->encrypt($apiKey);

        try {
            $webhookToken = $this->decrypt((string) ($existing['webhook_token'] ?? ''));
        } catch (RuntimeException) {
            // APP_KEY rotation invalidates the old ciphertext. A webhook token
            // is replaceable, so rotate it automatically on the next save.
            $webhookToken = '';
        }
        if ($webhookToken === '') {
            $webhookToken = Str::random(64);
        }
        $webhookToken = $this->encrypt($webhookToken);

        return [
            'api_key'       => $apiKey,
            'timeout'       => max(3, min(60, (int) ($incoming['timeout'] ?? $existing['timeout'] ?? 15))),
            'webhook_token' => $webhookToken,
        ];
    }

    public function configured(): bool
    {
        try {
            return $this->runtime()['api_key'] !== '';
        } catch (RuntimeException) {
            return false;
        }
    }

    public function webhookToken(): string
    {
        return $this->runtime()['webhook_token'];
    }

    private function encrypt(string $value): string
    {
        if ($value === '' || str_starts_with($value, self::ENCRYPTED_PREFIX)) {
            return $value;
        }

        return self::ENCRYPTED_PREFIX . Crypt::encryptString($value);
    }

    private function decrypt(string $value): string
    {
        $value = trim($value);
        if ($value === '' || ! str_starts_with($value, self::ENCRYPTED_PREFIX)) {
            return $value;
        }

        try {
            return trim(Crypt::decryptString(substr($value, strlen(self::ENCRYPTED_PREFIX))));
        } catch (\Throwable $exception) {
            throw new RuntimeException('Brevo 凭据无法解密，请重新填写 API Key 并保存。', previous: $exception);
        }
    }
}
