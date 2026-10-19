<?php

use App\Http\Controllers\SaasDomainProbeController;
use App\Http\Controllers\SaasTlsAuthorizationController;
use Illuminate\Support\Facades\Route;

Route::get('/_longos/tls/authorize', SaasTlsAuthorizationController::class)
    ->middleware('throttle:saas-tls-ask')
    ->name('saas.tls.authorize');

Route::get('/_longos/domain/probe', SaasDomainProbeController::class)
    ->middleware('throttle:saas-domain-probe')
    ->name('saas.domain.probe');
