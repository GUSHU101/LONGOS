<?php

use Illuminate\Support\Facades\Route;
use Plugin\LongosLiveChat\Controllers\AdminChatController;

Route::prefix('live-chat')->name('longos_live_chat.')->middleware('throttle:240,1')->group(function () {
    Route::get('/', [AdminChatController::class, 'index'])->name('index');
    Route::get('conversations', [AdminChatController::class, 'conversations'])->name('conversations');
    Route::get('conversations/{conversation}/messages', [AdminChatController::class, 'messages'])->name('messages');
    Route::post('conversations/{conversation}/messages', [AdminChatController::class, 'store'])->name('messages.store');
    Route::post('conversations/{conversation}/close', [AdminChatController::class, 'close'])->name('close');
    Route::post('conversations/{conversation}/reopen', [AdminChatController::class, 'reopen'])->name('reopen');
    Route::post('heartbeat', [AdminChatController::class, 'heartbeat'])->name('heartbeat');
});
