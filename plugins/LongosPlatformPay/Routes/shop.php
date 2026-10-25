<?php

use Illuminate\Support\Facades\Route;
use Plugin\LongosPlatformPay\Controllers\CheckoutController;

Route::post('/longos-platform-pay/session', [CheckoutController::class, 'store'])
    ->middleware('throttle:platform-payment')
    ->name('longos_platform_pay.session');
