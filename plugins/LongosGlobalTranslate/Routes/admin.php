<?php

use Illuminate\Support\Facades\Route;
use Plugin\LongosGlobalTranslate\Controllers\AdminTranslationController;

Route::prefix('global-translate')->name('longos_global_translate.')
    ->middleware(['can:plugins_index', 'throttle:60,1'])->group(function (): void {
        Route::get('/', [AdminTranslationController::class, 'index'])->name('index');
        Route::get('diagnostics', [AdminTranslationController::class, 'diagnostics'])->name('diagnostics');
        Route::get('status', [AdminTranslationController::class, 'workspaceStatus'])->name('status');
        Route::put('settings', [AdminTranslationController::class, 'saveSettings'])->name('settings.save');
        Route::put('country-rules', [AdminTranslationController::class, 'saveCountryRule'])->name('country-rules.save');
        Route::put('country-rules/recommendations', [AdminTranslationController::class, 'applyCountryRecommendations'])->name('country-rules.recommendations.apply');
        Route::post('providers', [AdminTranslationController::class, 'saveProvider'])->name('providers.save');
        Route::put('providers/{provider}', [AdminTranslationController::class, 'updateProvider'])->whereNumber('provider')->name('providers.update');
        Route::delete('providers/{provider}', [AdminTranslationController::class, 'deleteProvider'])->whereNumber('provider')->name('providers.delete');
        Route::post('providers/{provider}/verify', [AdminTranslationController::class, 'verifyProvider'])->whereNumber('provider')->name('providers.verify');
        Route::post('providers/{provider}/dispatch', [AdminTranslationController::class, 'dispatchProvider'])->whereNumber('provider')->name('providers.dispatch');
        Route::post('scan', [AdminTranslationController::class, 'scan'])->name('scan');
        Route::post('jobs', [AdminTranslationController::class, 'plan'])->name('jobs.plan');
        // This never replays an uncertain provider request. The controller
        // accepts only a positive operator confirmation and the service
        // re-queues only ledger rows proved non-billable.
        Route::post('jobs/{job}/retry-definite', [AdminTranslationController::class, 'retryDefiniteFailures'])->whereNumber('job')->name('jobs.retry-definite');
        Route::post('jobs/{job}/cancel', [AdminTranslationController::class, 'cancelJob'])->whereNumber('job')->name('jobs.cancel');
        Route::post('jobs/{job}/recover-claims', [AdminTranslationController::class, 'recoverJobClaims'])->whereNumber('job')->name('jobs.recover-claims');
        Route::delete('jobs/{job}', [AdminTranslationController::class, 'archiveJob'])->whereNumber('job')->name('jobs.archive');
        Route::post('glossary/protected', [AdminTranslationController::class, 'saveProtectedTerm'])->name('glossary.protected.save');
        Route::delete('glossary/protected/{term}', [AdminTranslationController::class, 'deleteProtectedTerm'])->whereNumber('term')->name('glossary.protected.delete');
        Route::get('translations', [AdminTranslationController::class, 'translations'])->name('translations.index');
        Route::delete('translations/bulk', [AdminTranslationController::class, 'deleteTranslations'])->name('translations.bulk-delete');
        Route::delete('library', [AdminTranslationController::class, 'resetLibraries'])->name('library.bulk-reset');
        Route::delete('library/{locale}', [AdminTranslationController::class, 'resetLibrary'])->where('locale', '[A-Za-z0-9_-]{2,24}')->name('library.reset');
        Route::put('translations/{translation}', [AdminTranslationController::class, 'updateTranslation'])->whereNumber('translation')->name('translations.update');
        Route::post('translations/{translation}/review', [AdminTranslationController::class, 'reviewTranslation'])->whereNumber('translation')->name('translations.review');
        Route::post('publications', [AdminTranslationController::class, 'publish'])->name('publications.publish');
        Route::post('publications/quick', [AdminTranslationController::class, 'quickPublish'])->name('publications.quick');
        Route::post('publications/{publication}/rollback', [AdminTranslationController::class, 'rollback'])->whereNumber('publication')->name('publications.rollback');
    });
