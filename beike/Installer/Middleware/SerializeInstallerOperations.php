<?php

namespace Beike\Installer\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Serializes state-changing installer requests across browser tabs and PHP
 * workers. The recovery state is resumable, but migrations and administrator
 * creation must never execute concurrently against the same database.
 */
final class SerializeInstallerOperations
{
    public function handle(Request $request, Closure $next): Response
    {
        ignore_user_abort(true);
        @set_time_limit((int) config('installer.runtime.max_execution_seconds', 0));

        $directory = storage_path('app');
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return $this->failure(trans('installer::installer_messages.environment.operation_lock_failed'));
        }

        $handle = @fopen($directory . '/installer-operation.lock', 'c+');
        if ($handle === false) {
            return $this->failure(trans('installer::installer_messages.environment.operation_lock_failed'));
        }
        @chmod($directory . '/installer-operation.lock', 0600);

        $waitSeconds = max(1, min(10, (int) config('installer.runtime.operation_lock_wait_seconds', 3)));
        $deadline    = microtime(true) + $waitSeconds;
        $locked      = false;

        do {
            $locked = flock($handle, LOCK_EX | LOCK_NB);
            if (! $locked) {
                usleep(100000);
            }
        } while (! $locked && microtime(true) < $deadline);

        if (! $locked) {
            fclose($handle);

            return $this->failure(
                trans('installer::installer_messages.environment.operation_in_progress'),
                $request,
            );
        }

        try {
            return $next($request);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function failure(string $message, ?Request $request = null): Response
    {
        try {
            Log::warning('LONGOS installer operation lock unavailable.', ['message' => $message]);
        } catch (Throwable) {
            // Reporting must not replace a recoverable installer response.
        }

        $route = $request?->routeIs('installer.database.run')
            ? 'installer.database'
            : 'installer.environment';

        return redirect()->route($route)->withErrors(['error' => $message]);
    }
}
