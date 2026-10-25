<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $headers = [
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag'  => 'noindex, nofollow, noarchive',
        ];

        // A fresh container must become reachable so the installer can run,
        // but an installed instance is ready only when PHP, writable runtime
        // paths, and the correct control database connection all work.
        if (! is_file(storage_path('installed'))) {
            return response()->json(['status' => 'installing'], 200, $headers);
        }

        try {
            foreach ([storage_path('framework'), storage_path('logs'), app()->bootstrapPath('cache')] as $path) {
                if (! is_dir($path) || ! is_writable($path)) {
                    throw new \RuntimeException('Runtime path is not writable.');
                }
            }

            $controlConnection = (bool) config('saas.enabled') ? 'saas' : config('database.default');
            DB::connection($controlConnection)->select('SELECT 1');

            if ((bool) config('saas.enabled')) {
                $controlSchema = Schema::connection('saas');
                foreach (['saas_sites', 'saas_domains', 'saas_platform_payment_sessions'] as $table) {
                    if (! $controlSchema->hasTable($table)) {
                        throw new \RuntimeException("Missing control table: {$table}");
                    }
                }

                if (! current_site()) {
                    throw new \RuntimeException('The request host did not resolve to an active site.');
                }
            }

            // The API health route does not start a session or render a view.
            // Verify the tables required by real storefront/admin requests so
            // Coolify cannot mark a release healthy while both pages return 500.
            $runtimeConnection = (bool) config('saas.enabled') ? 'tenant' : config('database.default');
            $runtimeSchema     = Schema::connection($runtimeConnection);
            foreach (['settings', 'sessions', 'admin_users'] as $table) {
                if (! $runtimeSchema->hasTable($table)) {
                    throw new \RuntimeException("Missing runtime table: {$table}");
                }
            }
        } catch (Throwable) {
            return response()->json(['status' => 'degraded'], 503, $headers);
        }

        return response()->json(['status' => 'ok'], 200, $headers);
    }
}
