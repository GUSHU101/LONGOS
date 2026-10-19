<?php

use App\Http\Controllers\CloudMailRelayController;
use Illuminate\Support\Facades\Route;

Route::prefix('_longos/mail-relay')
    ->middleware('throttle:cloud-mail-integration')
    ->group(function (): void {
        Route::get('health', [CloudMailRelayController::class, 'health'])->name('longos.mail-relay.health');
        Route::post('v1/send', [CloudMailRelayController::class, 'send'])->name('longos.mail-relay.send');
    });
