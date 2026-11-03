<?php

use Illuminate\Support\Facades\Route;
use Plugin\LongosGlobalTranslate\Controllers\AdminTranslationController;

Route::prefix('global-translate')->name('longos_global_translate.')
    ->middleware(['can:plugins_index', 'throttle:60,1'])->group(function (): void {
        Route::get('/', [AdminTranslationController::class, 'index'])->name('index');
        Route::get('diagnostics', [AdminTranslationController::class, 'diagnostics'])->name('diagnostics');
        Route::put('settings', [AdminTranslationController::class, 'saveSettings'])->name('settings.save');
        Route::put('country-rules', [AdminTranslationController::class, 'saveCountryRule'])->name('country-rules.save');
        Route::put('country-rules/recommendations', [AdminTranslationController::class, 'applyCountryRecommendations'])->name('country-rules.recommendations.apply');
        Route::post('providers', [AdminTranslationController::class, 'saveProvider'])->name('providers.save');
        Route::post('providers/{provider}/verify', [AdminTranslationController::class, 'verifyProvider'])->whereNumber('provider')->name('providers.verify');
        Route::post('providers/{provider}/dispatch', [AdminTranslationController::class, 'dispatchProvider'])->whereNumber('provider')->name('providers.dispatch');
        Route::post('scan', [AdminTranslationController::class, 'scan'])->name('scan');
        Route::post('jobs', [AdminTranslationController::class, 'plan'])->name('jobs.plan');
        Route::get('translations', [AdminTranslationController::class, 'translations'])->name('translations.index');
        Route::put('translations/{translation}', [AdminTranslationController::class, 'updateTranslation'])->whereNumber('translation')->name('translations.update');
        Route::post('translations/{translation}/review', [AdminTranslationController::class, 'reviewTranslation'])->whereNumber('translation')->name('translations.review');
        Route::post('publications', [AdminTranslationController::class, 'publish'])->name('publications.publish');
        Route::post('publications/{publication}/rollback', [AdminTranslationController::class, 'rollback'])->whereNumber('publication')->name('publications.rollback');
    });
