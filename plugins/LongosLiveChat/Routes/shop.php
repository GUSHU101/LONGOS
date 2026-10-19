<?php

use Illuminate\Support\Facades\Route;
use Plugin\LongosLiveChat\Controllers\ShopChatController;

Route::prefix('live-chat')->name('live_chat.')->middleware('throttle:120,1')->group(function () {
    Route::post('init', [ShopChatController::class, 'init'])->name('init');
    Route::get('{conversation}/messages', [ShopChatController::class, 'messages'])->name('messages');
    Route::post('{conversation}/messages', [ShopChatController::class, 'store'])->name('messages.store');
    Route::post('{conversation}/read', [ShopChatController::class, 'read'])->name('read');
});
