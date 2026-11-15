<?php

use Illuminate\Support\Facades\Route;
use Plugin\LongosProductReviews\Controllers\AdminReviewController;
use Plugin\LongosProductReviews\Controllers\AiReviewController;
use Plugin\LongosProductReviews\Controllers\AiReviewController;

Route::prefix('product-reviews')->name('longos_product_reviews.')->group(function (): void {
    Route::get('/', [AdminReviewController::class, 'index'])->name('index');
    Route::middleware(['can:plugins_index', 'throttle:15,1'])->group(function (): void {
        Route::post('ai/key', [AiReviewController::class, 'saveKey'])->name('ai.key');
        Route::post('ai/generate', [AiReviewController::class, 'generate'])->name('ai.generate');
    });
    Route::middleware(['can:plugins_index', 'throttle:15,1'])->group(function (): void {
        Route::post('ai/key', [AiReviewController::class, 'saveKey'])->name('ai.key');
        Route::post('ai/generate', [AiReviewController::class, 'generate'])->name('ai.generate');
    });
    Route::get('create', [AdminReviewController::class, 'create'])->name('create');
    Route::post('/', [AdminReviewController::class, 'store'])->name('store');
    Route::get('{review}/edit', [AdminReviewController::class, 'edit'])->whereNumber('review')->name('edit');
    Route::put('{review}', [AdminReviewController::class, 'update'])->whereNumber('review')->name('update');
    Route::patch('{review}/publication', [AdminReviewController::class, 'publication'])->whereNumber('review')->name('publication');
    Route::delete('{review}', [AdminReviewController::class, 'destroy'])->whereNumber('review')->name('destroy');
});
