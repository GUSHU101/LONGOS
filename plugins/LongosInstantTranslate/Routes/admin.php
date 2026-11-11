<?php

use Illuminate\Support\Facades\Route;
use Plugin\LongosInstantTranslate\Controllers\AdminSettingsController;

Route::prefix('instant-translate')
    ->name('longos_instant_translate.')
    ->middleware(['can:plugins_index', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/', [AdminSettingsController::class, 'index'])->name('index');
        Route::put('settings', [AdminSettingsController::class, 'update'])->name('settings.update');
        Route::post('providers', [AdminSettingsController::class, 'saveProvider'])->name('providers.store');
        Route::post('providers/{account}/verify', [AdminSettingsController::class, 'verifyProvider'])->whereNumber('account')->name('providers.verify');
        Route::put('providers/{account}/dispatch', [AdminSettingsController::class, 'toggleProvider'])->whereNumber('account')->name('providers.dispatch');
        Route::post('library/scan', [AdminSettingsController::class, 'scan'])->name('library.scan');
        Route::post('library/process-one', [AdminSettingsController::class, 'processOne'])->name('library.process-one');
    });
