<?php

namespace App\Saas\Support;

final class TenantRuntimeKey
{
    public static function sessionCookie(string $base, string $tenantIdentity, int $maximumLength = 120): string
    {
        $suffixLength = 16;
        $suffix       = substr(hash('sha256', $tenantIdentity), 0, $suffixLength);
        $separator    = '_';
        $base         = preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($base)) ?: 'longos_session';
        $baseLength   = max(1, $maximumLength - strlen($separator) - $suffixLength);

        return substr($base, 0, $baseLength) . $separator . $suffix;
    }

    public static function sessionDirectory(string $base, string $tenantCode): string
    {
        $tenantCode = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $tenantCode) ?: 'tenant';

        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenantCode;
    }
}
