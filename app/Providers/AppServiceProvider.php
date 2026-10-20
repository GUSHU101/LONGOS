<?php

namespace App\Providers;

use App\Services\DeploymentUpgradeService;
use Beike\Services\QueueService;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
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
        Queue::before(static function (JobProcessing $event): void {
            $payload = $event->job->payload();
            $timeout = filter_var($payload['timeout'] ?? null, FILTER_VALIDATE_INT);
            $lease   = $timeout === false || $timeout === null
                ? (int) config('queue.worker_heartbeat_lease', 300)
                : $timeout + 30;
            QueueService::recordWorkerHeartbeat(
                (string) $event->job->getQueue(),
                max(30, min(3900, $lease)),
                true,
            );
        });
        $releaseWorkerLease = static function (JobProcessed|JobExceptionOccurred $event): void {
            QueueService::recordWorkerHeartbeat((string) $event->job->getQueue(), 0, true);
        };
        Queue::after($releaseWorkerLease);
        Queue::exceptionOccurred($releaseWorkerLease);

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
