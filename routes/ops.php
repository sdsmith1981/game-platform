<?php

use GamePlatform\Ops\ClientErrorController;
use GamePlatform\Ops\FeedbackController;
use GamePlatform\Ops\WhatsNewController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    // Errors in players' browsers, passed on to the error tracker. Guests' pages can fail too.
    Route::post('client-errors', [ClientErrorController::class, 'store'])->middleware('throttle:20,1')->name('client-errors.store');

    Route::middleware(['auth', 'verified'])->group(function () {
        Route::post('whats-new/seen', [WhatsNewController::class, 'store'])->name('whats-new.seen');
        Route::post('feedback', [FeedbackController::class, 'store'])->middleware('throttle:5,1')->name('feedback.store');
    });
});
