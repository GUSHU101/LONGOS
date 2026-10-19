<?php

use Beike\Installer\Controllers\DatabaseController;
use Beike\Installer\Controllers\EnvironmentController;
use Beike\Installer\Controllers\FinalController;
use Beike\Installer\Controllers\PermissionsController;
use Beike\Installer\Controllers\RequirementsController;
use Beike\Installer\Controllers\WelcomeController;
use Beike\Installer\Middleware\SerializeInstallerOperations;
use Illuminate\Support\Facades\Route;

Route::prefix('installer')
    ->name('installer.')
    ->middleware(\App\Http\Middleware\SetLocaleInstaller::class)
    ->group(function () {
        Route::get('/', [WelcomeController::class, 'index'])->name('welcome');
        Route::get('requirements', [RequirementsController::class, 'index'])->name('requirements');
        Route::get('permissions', [PermissionsController::class, 'index'])->name('permissions');
        Route::middleware(['installer'])
            ->group(function () {
                Route::get('environment', [EnvironmentController::class, 'index'])->name('environment');
                Route::post('environment/save', [EnvironmentController::class, 'saveWizard'])
                    ->middleware(['throttle:10,1', SerializeInstallerOperations::class])
                    ->name('environment.save');
                Route::post('environment/validate_db', [EnvironmentController::class, 'validateDatabase'])->middleware('throttle:10,1')->name('environment.validate_db');
                Route::get('database', [DatabaseController::class, 'progress'])
                    ->name('database');
                Route::post('database/run', [DatabaseController::class, 'index'])
                    ->middleware(['throttle:12,1', SerializeInstallerOperations::class])
                    ->name('database.run');
                Route::get('final', [FinalController::class, 'index'])
                    ->middleware(SerializeInstallerOperations::class)
                    ->name('final');
            });

    });
