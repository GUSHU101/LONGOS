<?php

use Illuminate\Support\Facades\Route;
use Plugin\BeikeShopTestSandbox\Controllers\AdminRadarController;

Route::prefix('beikeshop-test')
    ->name('beikeshop_test_sandbox.')
    ->middleware('can:manage-test-checkout')
    ->group(function (): void {
        Route::get('radar', [AdminRadarController::class, 'index'])->name('radar');
    });
