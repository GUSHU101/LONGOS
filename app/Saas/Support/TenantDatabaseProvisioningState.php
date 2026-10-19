<?php

namespace App\Saas\Support;

/**
 * Crash-safe ownership state for separately provisioned tenant databases.
 */
final class TenantDatabaseProvisioningState
{
    /**
     * @param array<string, mixed> $meta
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public static function begin(array $meta, string $leaseToken): array
    {
        $canReuse = (bool) ($meta['database_created'] ?? false)
            || is_array($meta['database_creation_pending'] ?? null);

        $meta['database_creation_pending'] = [
            // Keep the raw provisioning lease out of serialized metadata.
            'lease_hash' => hash('sha256', $leaseToken),
            'started_at' => now()->toIso8601String(),
        ];

        return [$meta, $canReuse];
    }

    /** @param array<string, mixed> $meta */
    public static function complete(array $meta): array
    {
        $meta['database_created'] = true;
        unset($meta['database_creation_pending']);

        return $meta;
    }

    /** @param array<string, mixed> $meta */
    public static function abort(array $meta): array
    {
        unset($meta['database_creation_pending']);

        return $meta;
    }
}
