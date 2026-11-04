<?php

use GamePlatform\Billing\Http\CheckoutController;
use GamePlatform\Billing\Http\PlayTimeController;
use Illuminate\Support\Facades\Route;

// Stripe confirms payments here. It's signed, so it skips the CSRF check (and the session).
Route::post('stripe/webhook', [CheckoutController::class, 'webhook'])->name('stripe.webhook');

Route::middleware(['web', 'auth', 'verified'])->group(function () {
    Route::get('play-time', [PlayTimeController::class, 'index'])->name('play-time.index');
    Route::post('play-time', [PlayTimeController::class, 'store'])->name('play-time.store');
    Route::post('checkout', [CheckoutController::class, 'store'])->middleware('throttle:10,1')->name('checkout.store');
    Route::get('checkout/success', [CheckoutController::class, 'success'])->name('checkout.success');
});
