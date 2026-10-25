<?php

use App\Http\Controllers\PlatformPaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('_longos/pay')
    ->middleware(['platform.payment', 'throttle:platform-payment'])
    ->group(function (): void {
        Route::get('{token}', [PlatformPaymentController::class, 'show'])->where('token', '[a-f0-9]{64}')->name('platform-pay.show');
        Route::post('{token}/paypal/create', [PlatformPaymentController::class, 'paypalCreate'])->where('token', '[a-f0-9]{64}')->name('platform-pay.paypal.create');
        Route::post('{token}/paypal/capture', [PlatformPaymentController::class, 'paypalCapture'])->where('token', '[a-f0-9]{64}')->name('platform-pay.paypal.capture');
        Route::post('{token}/stripe/intent', [PlatformPaymentController::class, 'stripeIntent'])->where('token', '[a-f0-9]{64}')->name('platform-pay.stripe.intent');
        Route::post('{token}/stripe/complete', [PlatformPaymentController::class, 'stripeComplete'])->where('token', '[a-f0-9]{64}')->name('platform-pay.stripe.complete');
        Route::get('{token}/return/{provider}', [PlatformPaymentController::class, 'providerReturn'])->where(['token' => '[a-f0-9]{64}', 'provider' => 'paypal|stripe'])->name('platform-pay.return');
        Route::get('{token}/cancel', [PlatformPaymentController::class, 'cancel'])->where('token', '[a-f0-9]{64}')->name('platform-pay.cancel');
    });
