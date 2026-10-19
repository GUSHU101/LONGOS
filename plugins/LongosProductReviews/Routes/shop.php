<?php

use Illuminate\Support\Facades\Route;
use Plugin\LongosProductReviews\Controllers\ShopReviewController;

Route::get('products/{product}/customer-reviews', [ShopReviewController::class, 'index'])
    ->middleware('throttle:120,1')
    ->name('longos_product_reviews.index');
