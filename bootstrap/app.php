<?php

/*
|--------------------------------------------------------------------------
| Deployment cache guard
|--------------------------------------------------------------------------
|
| Laravel's compiled configuration contains absolute paths.  A cache built
| on Windows therefore cannot run in the Linux production container: it may
| reference paths like C:\\Users\\... before Laravel has a chance to report a
| useful error.  This guard runs before the application is created and only
| on non-Windows hosts.  It removes the complete compiled-cache set when the
| configuration cache clearly came from Windows; Laravel then loads the
| source configuration normally and can rebuild caches on the server.
|
*/

$basePath    = $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__);
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (DIRECTORY_SEPARATOR !== '\\') {
    $configCache = $basePath . '/bootstrap/cache/config.php';
    $cacheSource = is_file($configCache) ? @file_get_contents($configCache) : false;

    // An installer request must never boot from a cache left over by a
    // previous upload.  Archives intentionally exclude runtime cache files,
    // but restoring an old application volume may retain stale files. Clear
    // them before Laravel resolves any provider.
    $installerRequest = $requestPath === '/installer' || str_starts_with($requestPath, '/installer/');

    if ($installerRequest || (is_string($cacheSource) && str_contains($cacheSource, ':\\\\'))) {
        foreach (glob($basePath . '/bootstrap/cache/*.php') ?: [] as $cacheFile) {
            if (is_file($cacheFile)) {
                @unlink($cacheFile);
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| The first thing we will do is create a new Laravel application instance
| which serves as the "glue" for all the components of Laravel, and is
| the IoC container for the system binding all of the various parts.
|
*/

$app = new Illuminate\Foundation\Application($basePath);

/*
|--------------------------------------------------------------------------
| Bind Important Interfaces
|--------------------------------------------------------------------------
|
| Next, we need to bind some important interfaces into the container so
| we will be able to resolve them when needed. The kernels serve the
| incoming requests to this application from both the web and CLI.
|
*/

$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    App\Http\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

/*
|--------------------------------------------------------------------------
| Return The Application
|--------------------------------------------------------------------------
|
| This script returns the application instance. The instance is given to
| the calling script so we can separate the building of the instances
| from the actual running of the application and sending responses.
|
*/

return $app;
