<?php

namespace App\Saas\Support;

use App\Saas\Models\Site;
use Closure;
use Throwable;

/**
 * Keeps the originating tenant active while deferred request work executes.
 *
 * Laravel application terminating callbacks run after terminable middleware,
 * so the normal HTTP tenant middleware has already restored the control
 * context by then. Capturing the tenant explicitly prevents plugin callbacks
 * from reading settings or orders from another storefront.
 */
final class TenantDeferred
{
    public static function capture(callable $callback): Closure
    {
        $tenant = Site::current();

        return static function (...$arguments) use ($callback, $tenant): mixed {
            if (! $tenant) {
                return $callback(...$arguments);
            }

            return $tenant->execute(
                static fn (): mixed => $callback(...$arguments),
            );
        };
    }

    public static function terminating(callable $callback): void
    {
        $captured = self::capture($callback);

        app()->terminating(static function () use ($captured): void {
            try {
                $captured();
            } catch (Throwable $exception) {
                // A best-effort integration must never terminate an Octane
                // or queue worker just because its tenant was changed or its
                // database became unavailable after the response was sent.
                report($exception);
            }
        });
    }
}
