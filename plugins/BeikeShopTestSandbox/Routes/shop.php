<?php

use Illuminate\Support\Facades\Route;
use Plugin\BeikeShopTestSandbox\Controllers\TestCheckoutPluginController;

// Each visitor receives an opaque numeric checkout-session URL from the
// /checkout middleware redirect. It identifies the browser flow only; it is
// never an order number and is not used to retrieve order data.
Route::get('checkout/{checkoutSession}', [TestCheckoutPluginController::class, 'index'])
    ->where('checkoutSession', '\\d{12}')
    ->name('beikeshop_test_sandbox.checkout_session');

Route::prefix('beikeshop-test')->name('beikeshop_test_sandbox.')->group(function (): void {
    // Keep old plugin URLs working while making /checkout the sole customer-facing address.
    Route::redirect('checkout', '/checkout')->name('checkout');
    Route::get('refund-policy', [TestCheckoutPluginController::class, 'refundPolicy'])->name('refund_policy');
    Route::get('3ds/{network}', [TestCheckoutPluginController::class, 'threeDsChallenge'])
        ->where('network', 'visa|mastercard|amex|discover|dinersclub|jcb|unionpay')
        ->name('three_ds');
});
