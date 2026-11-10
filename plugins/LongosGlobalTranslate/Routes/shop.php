<?php

use Illuminate\Support\Facades\Route;
use Plugin\LongosGlobalTranslate\Controllers\ShopTranslationController;

Route::prefix('global-translate')->name('longos_global_translate.')
    ->group(function (): void {
        // Every navigation needs a fresh, visitor-specific locale decision.
        // Its tiny read-only config must not share the restrictive mutation
        // budget, particularly behind trusted CDNs and busy storefronts.
        Route::get('config', [ShopTranslationController::class, 'config'])->middleware('throttle:300,1')->name('config');
        Route::middleware('throttle:60,1')->group(function (): void {
            Route::post('locale', [ShopTranslationController::class, 'locale'])->name('locale');
            Route::get('dictionary/interface', [ShopTranslationController::class, 'interfaceDictionary'])->name('interface-dictionary');
            Route::get('dictionary/batch', [ShopTranslationController::class, 'batchDictionary'])->name('dictionary-batch');
            Route::get('dictionary/{kind}/{entityId}', [ShopTranslationController::class, 'dictionary'])
                ->whereIn('kind', ['product', 'category', 'page', 'page_category', 'builder', 'chrome'])
                ->whereNumber('entityId')
                ->name('dictionary');
        });
    });
