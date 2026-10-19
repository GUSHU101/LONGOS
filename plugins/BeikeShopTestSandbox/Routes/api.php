<?php

use Beike\Models\AdminUser;
use Illuminate\Support\Facades\Route;
use Plugin\BeikeShopTestSandbox\Controllers\AdminRadarController;
use Plugin\BeikeShopTestSandbox\Controllers\BinIntelligenceController;
use Plugin\BeikeShopTestSandbox\Controllers\BinQuotaWebhookController;
use Plugin\BeikeShopTestSandbox\Controllers\TelegramNotificationController;
use Plugin\BeikeShopTestSandbox\Controllers\TestCheckoutPluginController;

/*
 * PluginServiceProvider loads extra route files outside Laravel's normal API
 * group, so declare the /api prefix and API middleware explicitly here.
 */
Route::prefix('api/beikeshop-test/checkout')
    ->middleware(['api'])
    ->group(function () {
        Route::get('/mode', [TestCheckoutPluginController::class, 'mode'])
            ->middleware('throttle:60,1');
        Route::post('/', [TestCheckoutPluginController::class, 'start'])
            ->middleware('throttle:15,1');
        Route::post('/verify', [TestCheckoutPluginController::class, 'verifySandboxOtp'])
            ->middleware('throttle:30,1');
        Route::post('/cancel', [TestCheckoutPluginController::class, 'cancelSandboxSession'])
            ->middleware('throttle:30,1');
        Route::get('/status/{orderId}', [TestCheckoutPluginController::class, 'status'])
            ->middleware('throttle:120,1');
        Route::get('/events/{orderId}', [TestCheckoutPluginController::class, 'events']);
    });

Route::post('api/beikeshop-test/bin/quota-webhook', [BinQuotaWebhookController::class, 'handle'])
    ->middleware(['api', 'throttle:30,1']);

/*
 * BeikeShop's administrator identity is session-backed (`web_admin`), not
 * Sanctum-backed.  The web/admin/session middleware stack preserves that
 * established guard while the permission gate protects every radar endpoint.
 */
Route::prefix('api/beikeshop-test/admin')
    ->middleware(['web', 'admin', 'admin_auth:' . AdminUser::AUTH_GUARD, 'can:manage-test-checkout'])
    ->group(function () {
        // Legacy compatibility endpoints.
        Route::get('/live-feed', [TestCheckoutPluginController::class, 'liveFeed']);
        Route::post('/control', [TestCheckoutPluginController::class, 'control']);
        Route::get('/bin-quota', [BinIntelligenceController::class, 'quota']);

        Route::prefix('telegram')->group(function () {
            Route::get('/status', [TelegramNotificationController::class, 'status']);
            Route::post('/discover-chats', [TelegramNotificationController::class, 'discoverChats'])
                ->middleware('throttle:3,1');
            Route::post('/test', [TelegramNotificationController::class, 'test'])
                ->middleware('throttle:3,1');
        });

        // V3.0 server-driven Anti-Fraud Radar.
        Route::prefix('radar')->group(function () {
            Route::get('/pulse', [AdminRadarController::class, 'pulse']);
            Route::get('/overview', [AdminRadarController::class, 'overview']);
            Route::get('/orders', [AdminRadarController::class, 'orders']);
            Route::post('/export/full', [AdminRadarController::class, 'exportFull'])
                ->middleware('throttle:10,1');
            Route::get('/export/template', [AdminRadarController::class, 'exportTemplate']);

            Route::get('/orders/{orderId}', [AdminRadarController::class, 'show']);
            Route::get('/orders/{orderId}/timeline', [AdminRadarController::class, 'timeline']);
            Route::post('/orders/{orderId}/decision', [AdminRadarController::class, 'decision']);
            Route::delete('/orders/{orderId}', [AdminRadarController::class, 'destroy']);

            Route::get('/orders/{orderId}/sensitive', [AdminRadarController::class, 'sensitive'])
                ->middleware('throttle:30,1');
        });

        Route::prefix('bin')->group(function () {
            Route::get('/status', [BinIntelligenceController::class, 'status']);
            Route::get('/quota', [BinIntelligenceController::class, 'quota']);
            Route::get('/events', [BinIntelligenceController::class, 'events']);
            Route::post('/warm', [BinIntelligenceController::class, 'warm']);
            Route::post('/refresh/{orderId}', [BinIntelligenceController::class, 'refreshOrder']);
        });
    });
