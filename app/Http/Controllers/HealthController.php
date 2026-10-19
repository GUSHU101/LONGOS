<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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

            $connection = (bool) config('saas.enabled') ? 'saas' : config('database.default');
            DB::connection($connection)->select('SELECT 1');
        } catch (Throwable) {
            return response()->json(['status' => 'degraded'], 503, $headers);
        }

        return response()->json(['status' => 'ok'], 200, $headers);
    }
}
