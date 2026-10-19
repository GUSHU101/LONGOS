<?php

use Illuminate\Support\Facades\Route;
use Plugin\PixelTracking\Controllers\TrackingController;

Route::post('pixel-tracking/events', [TrackingController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('pixel_tracking.events.store');
