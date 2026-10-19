<?php

use Illuminate\Support\Facades\Route;
use Plugin\LongosProductReviews\Controllers\AdminReviewController;

Route::prefix('product-reviews')->name('longos_product_reviews.')->group(function (): void {
    Route::get('/', [AdminReviewController::class, 'index'])->name('index');
    Route::get('create', [AdminReviewController::class, 'create'])->name('create');
    Route::post('/', [AdminReviewController::class, 'store'])->name('store');
    Route::get('{review}/edit', [AdminReviewController::class, 'edit'])->whereNumber('review')->name('edit');
    Route::put('{review}', [AdminReviewController::class, 'update'])->whereNumber('review')->name('update');
    Route::patch('{review}/publication', [AdminReviewController::class, 'publication'])->whereNumber('review')->name('publication');
    Route::delete('{review}', [AdminReviewController::class, 'destroy'])->whereNumber('review')->name('destroy');
});
