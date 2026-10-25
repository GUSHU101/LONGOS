<?php

use Illuminate\Support\Facades\Route;
use Plugin\LongosPlatformPay\Controllers\CheckoutController;

Route::post('/longos-platform-pay/session', [CheckoutController::class, 'store'])
    ->middleware('throttle:platform-payment')
    ->name('longos_platform_pay.session');

Route::prefix('/longos-platform-pay/{token}')
    ->where(['token' => '[a-f0-9]{64}'])
    ->middleware('throttle:platform-payment')
    ->group(function (): void {
        Route::post('/paypal/create', [CheckoutController::class, 'paypalCreate'])->name('longos_platform_pay.paypal.create');
        Route::post('/paypal/capture', [CheckoutController::class, 'paypalCapture'])->name('longos_platform_pay.paypal.capture');
        Route::post('/stripe/intent', [CheckoutController::class, 'stripeIntent'])->name('longos_platform_pay.stripe.intent');
        Route::post('/stripe/complete', [CheckoutController::class, 'stripeComplete'])->name('longos_platform_pay.stripe.complete');
    });
