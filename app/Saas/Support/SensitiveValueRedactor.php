<?php

namespace App\Saas\Support;

use Throwable;

final class SensitiveValueRedactor
{
    /**
     * Remove configured credentials and common inline secret assignments from
     * errors before they are returned by APIs or persisted in audit records.
     *
     * @param array<int, mixed> $additionalSecrets
     */
    public static function redact(string $value, array $additionalSecrets = []): string
    {
        $configuredSecrets = [];

        try {
            foreach (['mysql', 'pgsql', 'saas', 'tenant', 'tenant_admin'] as $connection) {
                $configuredSecrets[] = config("database.connections.{$connection}.password");
            }
            $configuredSecrets[] = config('saas.central.password');
            $configuredSecrets[] = config('saas.tenant.password');
            $configuredSecrets[] = config('saas.tenant_admin.password');
            foreach ((array) config('mail.mailers', []) as $mailer) {
                if (! is_array($mailer)) {
                    continue;
                }
                foreach (['password', 'secret', 'api_key', 'token'] as $field) {
                    $configuredSecrets[] = $mailer[$field] ?? null;
                }
            }
            $configuredSecrets[] = config('services.mailgun.secret');
            $configuredSecrets[] = config('services.sendgrid.api_key');
            $configuredSecrets[] = config('services.sendcloud.api_key');
        } catch (Throwable) {
            // The redactor is also used by isolated unit tests before Laravel
            // has created its service container.
        }

        $normalize = static fn (mixed $secret): string => is_scalar($secret) ? trim((string) $secret) : '';
        $secrets   = array_values(array_unique(array_merge(
            array_filter(array_map($normalize, $additionalSecrets), static fn (string $secret): bool => $secret !== ''),
            array_filter(array_map($normalize, $configuredSecrets), static fn (string $secret): bool => mb_strlen($secret) >= 4),
        )));
        usort($secrets, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($secrets as $secret) {
            $value = str_replace($secret, '[redacted]', $value);
        }

        $value = preg_replace(
            '/(authorization\s*[=:]\s*)(?:bearer|basic)\s+[^\s,;]+/i',
            '$1[redacted]',
            $value,
        ) ?? $value;
        $value = preg_replace(
            '/\b(bearer|basic)\s+[^\s,;]+/i',
            '$1 [redacted]',
            $value,
        ) ?? $value;
        $value = preg_replace(
            '/((?:"|\')?(?:password|passwd|pwd|secret|token|signature|api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|authorization|credential)(?:"|\')?\s*[=:]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;&#}]+)/i',
            '$1[redacted]',
            $value,
        ) ?? $value;
        $value = preg_replace_callback(
            '/([?&](?:password|passwd|pwd|secret|token|signature|api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|credential)=[^&#\s]*)/i',
            static function (array $matches): string {
                $position = strpos($matches[1], '=');

                return $position === false ? '[redacted]' : substr($matches[1], 0, $position + 1) . '[redacted]';
            },
            $value,
        ) ?? $value;

        return preg_replace('#(://[^:/@\s]+:)[^@\s]+(@)#', '$1[redacted]$2', $value) ?? $value;
    }
}
