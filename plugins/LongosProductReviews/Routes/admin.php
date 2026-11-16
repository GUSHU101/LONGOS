<?php

use Illuminate\Support\Facades\Route;
use Plugin\LongosProductReviews\Controllers\AdminReviewController;
use Plugin\LongosProductReviews\Controllers\AdminReviewGenerationController;

Route::prefix('product-reviews')->name('longos_product_reviews.')->group(function (): void {
    Route::get('/', [AdminReviewController::class, 'index'])->name('index');
    Route::post('bulk', [AdminReviewController::class, 'bulk'])->name('bulk');
    Route::get('generate', [AdminReviewGenerationController::class, 'index'])->name('generate');
    Route::post('generate/settings', [AdminReviewGenerationController::class, 'saveSettings'])->name('generate.settings');
    Route::post('generate/test-connection', [AdminReviewGenerationController::class, 'testConnection'])->middleware('throttle:product-review-admin')->name('generate.test');
    Route::post('generate/preview', [AdminReviewGenerationController::class, 'preview'])->middleware('throttle:product-review-admin')->name('generate.preview');
    Route::get('generate/batches/{batch}', [AdminReviewGenerationController::class, 'status'])->whereUuid('batch')->middleware('throttle:120,1')->name('generate.status');
    Route::post('generate/batches/{batch}/cancel', [AdminReviewGenerationController::class, 'cancel'])->whereUuid('batch')->middleware('throttle:20,1')->name('generate.cancel');
    Route::post('generate/commit', [AdminReviewGenerationController::class, 'commit'])->name('generate.commit');
    Route::get('create', [AdminReviewController::class, 'create'])->name('create');
    Route::post('/', [AdminReviewController::class, 'store'])->name('store');
    Route::get('{review}/edit', [AdminReviewController::class, 'edit'])->whereNumber('review')->name('edit');
    Route::put('{review}', [AdminReviewController::class, 'update'])->whereNumber('review')->name('update');
    Route::patch('{review}/publication', [AdminReviewController::class, 'publication'])->whereNumber('review')->name('publication');
    Route::delete('{review}', [AdminReviewController::class, 'destroy'])->whereNumber('review')->name('destroy');
});
