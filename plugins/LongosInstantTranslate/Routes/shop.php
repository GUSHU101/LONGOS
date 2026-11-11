<?php

use Illuminate\Support\Facades\Route;
use Plugin\LongosInstantTranslate\Controllers\ShopLibraryController;
use Plugin\LongosInstantTranslate\Controllers\ShopLocaleController;

Route::prefix('_longos/instant-translate')
    ->name('longos_instant_translate.')
    ->middleware('throttle:120,1')
    ->group(function (): void {
        Route::get('context', ShopLocaleController::class)->name('context');
        Route::post('library', ShopLibraryController::class)->name('library');
    });
