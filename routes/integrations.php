<?php

use App\Http\Controllers\BrevoWebhookController;
use App\Http\Controllers\CloudMailRelayController;
use App\Http\Controllers\PlatformPaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('_longos/mail-relay')
    ->middleware('throttle:cloud-mail-integration')
    ->group(function (): void {
        Route::get('health', [CloudMailRelayController::class, 'health'])->name('longos.mail-relay.health');
        Route::get('dns/manifest', [CloudMailRelayController::class, 'dnsManifest'])
            ->middleware('throttle:cloud-mail-dns-manifest')
            ->name('longos.mail-relay.dns-manifest');
        Route::post('v1/send', [CloudMailRelayController::class, 'send'])->name('longos.mail-relay.send');
    });

Route::post('_longos/pay/webhooks/stripe', [PlatformPaymentWebhookController::class, 'stripe'])
    ->middleware(['platform.payment', 'throttle:platform-payment-webhook'])
    ->name('platform-pay.webhook.stripe');

Route::post('_longos/mail/webhooks/brevo', BrevoWebhookController::class)
    ->middleware('throttle:brevo-webhook')
    ->name('longos.brevo.webhook');
