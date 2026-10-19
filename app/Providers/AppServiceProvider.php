<?php

namespace App\Providers;

use App\Services\DeploymentUpgradeService;
use Beike\Services\QueueService;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->useLangPath(base_path('lang'));
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Queue::looping(static function (Looping $event): void {
            QueueService::recordWorkerHeartbeat((string) $event->queue);
        });

        if (config('beike.force_url_https', false)) {
            URL::forceScheme('https');
        }

        // Define CURL_SSLVERSION_TLSv1_2 constant if not exists to prevent errors in older cURL versions
        if (! defined('CURL_SSLVERSION_TLSv1_2')) {
            define('CURL_SSLVERSION_TLSv1_2', 6);
        }

        // After a settled legacy filesystem replacement, complete safe release maintenance in
        // the terminate phase of the first normal request. It never runs while
        // the installer is active.
        if (config('longos.auto_upgrade', false) && ! $this->app->runningInConsole() && ! is_installer()) {
            $this->app->terminating(static fn () => DeploymentUpgradeService::run());
        }
    }
}
