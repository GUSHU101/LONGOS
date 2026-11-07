<?php

use Illuminate\Support\Facades\Route;
use Plugin\LongosGlobalTranslate\Controllers\ShopTranslationController;

Route::prefix('global-translate')->name('longos_global_translate.')
    ->middleware('throttle:60,1')->group(function (): void {
        Route::get('config', [ShopTranslationController::class, 'config'])->name('config');
        Route::post('locale', [ShopTranslationController::class, 'locale'])->name('locale');
        Route::get('dictionary/interface', [ShopTranslationController::class, 'interfaceDictionary'])->name('interface-dictionary');
        Route::get('dictionary/batch', [ShopTranslationController::class, 'batchDictionary'])->name('dictionary-batch');
        Route::get('dictionary/{kind}/{entityId}', [ShopTranslationController::class, 'dictionary'])
            ->whereIn('kind', ['product', 'category', 'page', 'page_category', 'builder', 'chrome'])
            ->whereNumber('entityId')
            ->name('dictionary');
    });
