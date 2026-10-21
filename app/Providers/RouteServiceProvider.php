<?php

namespace App\Providers;

use App\Saas\Models\Site;
use Beike\Models\AdminUser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    // protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));

            // Machine-to-machine endpoints deliberately do not start a
            // browser session and do not use CSRF cookies. Each route applies
            // its own scoped bearer-token authentication.
            Route::middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)
                ->namespace($this->namespace)
                ->group(base_path('routes/integrations.php'));

            Route::group([], base_path('routes/internal.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(600)->by(optional($request->user())->id ?: $request->ip());
        });

        RateLimiter::for('auth-login', function (Request $request) {
            [$surface, $host, $email] = $this->authenticationRateLimitKeys($request);

            return [
                Limit::perMinute(10)->by("auth-login:{$surface}:{$host}:ip:{$request->ip()}"),
                Limit::perMinute(5)->by("auth-login:{$surface}:{$host}:email:{$email}"),
            ];
        });

        RateLimiter::for('auth-recovery', function (Request $request) {
            [$surface, $host, $email] = $this->authenticationRateLimitKeys($request);

            return [
                Limit::perMinute(5)->by("auth-recovery:{$surface}:{$host}:ip:{$request->ip()}"),
                Limit::perHour(20)->by("auth-recovery:{$surface}:{$host}:ip-hour:{$request->ip()}"),
                Limit::perMinute(3)->by("auth-recovery:{$surface}:{$host}:email:{$email}"),
            ];
        });

        RateLimiter::for('auth-register', function (Request $request) {
            $host = strtolower($request->getHost());

            return [
                Limit::perMinute(5)->by("auth-register:{$host}:ip:{$request->ip()}"),
                Limit::perHour(30)->by("auth-register:{$host}:ip-hour:{$request->ip()}"),
            ];
        });

        RateLimiter::for('checkout-address-search', function (Request $request) {
            $host = strtolower($request->getHost());

            return [
                Limit::perMinute(30)->by("checkout-address-search:{$host}:ip:{$request->ip()}"),
                Limit::perHour(200)->by("checkout-address-search:{$host}:ip-hour:{$request->ip()}"),
            ];
        });

        RateLimiter::for('storefront-analytics', function (Request $request) {
            $host      = strtolower($request->getHost());
            $sessionId = $request->hasSession() ? $request->session()->getId() : 'no-session';

            return [
                Limit::perMinute(12)->by("storefront-analytics:{$host}:session:" . hash('sha256', $sessionId)),
                Limit::perMinute(120)->by("storefront-analytics:{$host}:ip:{$request->ip()}"),
            ];
        });

        RateLimiter::for('saas-control', function (Request $request) {
            $adminId = Auth::guard(AdminUser::AUTH_GUARD)->id() ?: 'guest';

            return [
                Limit::perMinute(30)->by("saas-control:{$adminId}:{$request->ip()}"),
                Limit::perHour(200)->by("saas-control:{$adminId}"),
            ];
        });

        RateLimiter::for('saas-tenant-domains', function (Request $request) {
            $adminId = Auth::guard(AdminUser::AUTH_GUARD)->id() ?: 'guest';
            $siteId  = Site::current()?->getKey() ?: 'unresolved';

            return [
                Limit::perMinute(10)->by("saas-tenant-domains:{$siteId}:{$adminId}:{$request->ip()}"),
                Limit::perHour(60)->by("saas-tenant-domains:{$siteId}:{$adminId}"),
            ];
        });

        RateLimiter::for('saas-tls-ask', function (Request $request) {
            $domain = strtolower(trim((string) $request->query('domain', 'invalid')));
            $source = $request->ip();

            return [
                // Caddy is normally the single caller. The source-wide limit
                // prevents an SNI domain spray from creating unbounded indexed
                // database lookups, while the per-domain limit absorbs retries.
                Limit::perMinute(600)->by("saas-tls-ask:{$source}"),
                Limit::perMinute(30)->by("saas-tls-ask:{$source}:{$domain}"),
            ];
        });

        RateLimiter::for('cloud-mail-integration', function (Request $request) {
            $credential = hash('sha256', (string) $request->bearerToken());
            $host       = strtolower($request->getHost());

            return [
                Limit::perMinute(120)->by("cloud-mail:token:{$host}:{$credential}"),
                Limit::perMinute(240)->by("cloud-mail:ip:{$host}:{$request->ip()}"),
            ];
        });

        RateLimiter::for('cloud-mail-dns-manifest', function (Request $request) {
            $credential = hash('sha256', (string) $request->bearerToken());
            $host       = strtolower($request->getHost());

            return [
                Limit::perMinute(6)->by("cloud-mail-dns-manifest:token:{$host}:{$credential}"),
                Limit::perHour(30)->by("cloud-mail-dns-manifest:ip:{$host}:{$request->ip()}"),
            ];
        });

        RateLimiter::for('integration-token-reveal', function (Request $request) {
            $adminId = Auth::guard(AdminUser::AUTH_GUARD)->id() ?: 'guest';

            return [
                Limit::perMinute(10)->by("integration-token-reveal:{$adminId}:{$request->ip()}"),
                Limit::perHour(60)->by("integration-token-reveal:{$adminId}"),
            ];
        });

        RateLimiter::for('saas-domain-probe', function (Request $request) {
            $domainId = (string) $request->query('id', 'invalid');
            $source   = $request->ip();

            return [
                Limit::perMinute(120)->by("saas-domain-probe:{$source}"),
                Limit::perMinute(10)->by("saas-domain-probe:{$source}:{$domainId}"),
            ];
        });
    }

    /** @return array{string, string, string} */
    private function authenticationRateLimitKeys(Request $request): array
    {
        $surface = (string) ($request->route()?->getName() ?: $request->path());
        $host    = strtolower($request->getHost());
        $email   = hash('sha256', strtolower(trim((string) $request->input('email', 'missing'))));

        return [$surface, $host, $email];
    }
}
