<?php

namespace Beike\Installer\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class InstallerServiceProvider extends ServiceProvider
{
    /**
     * @throws \Exception
     */
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/../Routes/installer.php');

        if (! is_installer()) {
            return;
        }

        // The installer must also work when a release ships with Laravel's
        // config cache already generated. mergeConfigFrom() is intentionally
        // skipped by Laravel in that state, which previously left
        // config('installer') null and made /installer/requirements crash.
        if (! is_array(config('installer')) || ! config('installer.core.minPhpVersion')) {
            config(['installer' => require base_path('beike/Installer/config.php')]);
        }

        Schema::defaultStringLength(191);

        $this->mergeConfigFrom(__DIR__ . '/../config.php', 'installer');
        $this->mergeConfigFrom(__DIR__ . '/../../Config/beike.php', 'beike');
        $this->loadViewsFrom(__DIR__ . '/../Views', 'installer');

        $pathInstaller = base_path('beike/Installer');
        $this->loadTranslationsFrom("{$pathInstaller}/Lang", 'installer');
    }
}
