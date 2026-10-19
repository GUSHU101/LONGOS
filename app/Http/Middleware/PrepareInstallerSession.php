<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Give the web installer a stable, host-only file session.
 *
 * The installer rewrites .env between the form POST and the migration
 * redirect.  Its session must therefore not inherit a tenant-specific cookie,
 * a database-backed session driver, or a cookie domain from the application
 * that happened to be configured before installation started.
 */
final class PrepareInstallerSession
{
    public function handle(Request $request, Closure $next): Response
    {
        app('files')->ensureDirectoryExists(storage_path('framework/sessions'), 0775);

        config([
            'session.driver' => 'file',
            'session.files'  => storage_path('framework/sessions'),
            'session.cookie' => 'longos_installer_session',
            'session.path'   => '/installer',
            'session.domain' => null,
            'session.secure' => $request->isSecure() || is_secure(),
        ]);

        // A long-lived worker may have resolved the session manager for an
        // earlier tenant request. Force StartSession to build the installer
        // file store from the isolated configuration above.
        if (app()->resolved('session')) {
            app('session')->forgetDrivers();
        }

        return $next($request);
    }
}
