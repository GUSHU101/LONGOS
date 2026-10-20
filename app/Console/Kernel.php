<?php

namespace App\Console;

use Beike\Console\Commands\AnalyzeShopifyMigration;
use Beike\Console\Commands\AuditLocations;
use Beike\Console\Commands\BeikeShopInstall;
use Beike\Console\Commands\GenerateShopifyPuckRegistry;
use Beike\Console\Commands\InspectShopifyTheme;
use Beike\Console\Commands\MigrateShopifyTheme;
use Beike\Console\Commands\Sequence;
use Beike\Console\Commands\SyncLocations;
use Beike\Console\Commands\ValidateShopifyMigration;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        BeikeShopInstall::class,
        InspectShopifyTheme::class,
        GenerateShopifyPuckRegistry::class,
        AnalyzeShopifyMigration::class,
        AuditLocations::class,
        ValidateShopifyMigration::class,
        MigrateShopifyTheme::class,
        Sequence::class,
        SyncLocations::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // A running scheduler container is not sufficient proof that Laravel
        // is still dispatching due events. Keep a tiny shared-volume
        // heartbeat that Docker can check without querying the database or
        // exposing another HTTP endpoint. A stalled schedule:work loop will
        // become unhealthy after the bounded Compose grace period.
        $schedule->call(static function (): void {
            $path = storage_path('framework/scheduler-heartbeat');
            if (file_put_contents($path, now()->toIso8601String() . PHP_EOL, LOCK_EX) === false) {
                throw new \RuntimeException('Unable to persist the scheduler heartbeat.');
            }
        })->name('longos:scheduler-heartbeat')->everyMinute();

        if (config('saas.enabled')) {
            $schedule->command('saas:run process:order --continue-on-error')
                ->everyFiveMinutes()
                ->onOneServer()
                ->withoutOverlapping();
            $schedule->command('saas:run fetch:currency-rate --if-setting=base.rate_api_key --continue-on-error')
                ->dailyAt('02:15')
                ->onOneServer()
                ->withoutOverlapping();
            $schedule->command('saas:health --active-only')
                ->dailyAt('03:30')
                ->onOneServer()
                ->withoutOverlapping();
            $schedule->command('saas:domains:verify-pending')
                ->everyFiveMinutes()
                ->onOneServer()
                ->withoutOverlapping();
            $schedule->command('saas:idempotency:prune')
                ->hourly()
                ->onOneServer()
                ->withoutOverlapping();
            $schedule->command('saas:run longos:live-chat:prune --continue-on-error')
                ->hourlyAt(15)
                ->onOneServer()
                ->withoutOverlapping();
            $schedule->command('saas:run longos:mail-logs:prune --continue-on-error')
                ->dailyAt('03:45')
                ->onOneServer()
                ->withoutOverlapping();
            $schedule->command('saas:run longos:locations:sync --continue-on-error')
                ->weeklyOn(1, '04:20')
                ->onOneServer()
                ->withoutOverlapping();
        } else {
            $schedule->command('process:order')->everyFiveMinutes();
            $schedule->command('fetch:currency-rate')
                ->dailyAt('02:15')
                ->withoutOverlapping()
                ->when(fn () => trim((string) system_setting('base.rate_api_key', '')) !== '');
            $schedule->command('longos:live-chat:prune')
                ->hourlyAt(15)
                ->withoutOverlapping();
            $schedule->command('longos:mail-logs:prune')
                ->dailyAt('03:45')
                ->withoutOverlapping();
            $schedule->command('longos:locations:sync')
                ->weeklyOn(1, '04:20')
                ->withoutOverlapping();
        }

        $schedule->command('cloudflare:refresh-ip-ranges')
            ->dailyAt('03:00')
            ->withoutOverlapping();

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $paths = [
            __DIR__ . '/Commands',
            base_path('beike/Console/Commands'),
        ];
        $this->load($paths);

        require base_path('routes/console.php');
    }
}
