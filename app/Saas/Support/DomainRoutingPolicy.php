<?php

namespace App\Saas\Support;

use App\Saas\Models\Domain;

final class DomainRoutingPolicy
{
    /** @param array<int, string> $exemptPrefixes */
    public static function shouldRedirect(
        string $method,
        string $path,
        bool $isPrimary,
        string $routingBehavior,
        array $exemptPrefixes,
    ): bool {
        if (! in_array(strtoupper($method), ['GET', 'HEAD'], true)
            || $isPrimary
            || $routingBehavior !== Domain::ROUTING_REDIRECT) {
            return false;
        }

        $path = trim($path, '/');
        foreach ($exemptPrefixes as $prefix) {
            $prefix = trim($prefix, '/');
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                return false;
            }
        }

        return true;
    }
}
