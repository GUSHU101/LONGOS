<?php

namespace App\Http\Middleware;

use App\Saas\Support\SensitiveValueRedactor;
use Beike\Installer\Helpers\EnvironmentManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

class SetLocaleInstaller
{
    public function handle(Request $request, Closure $next)
    {
        try {
            (new EnvironmentManager)->getEnvContent();
        } catch (\Throwable $exception) {
            Log::warning('Installer could not prepare the .env file.', [
                'exception' => $exception::class,
                'message'   => SensitiveValueRedactor::redact($exception->getMessage()),
            ]);
        }

        App::setLocale('zh_cn');

        return $next($request);
    }
}
