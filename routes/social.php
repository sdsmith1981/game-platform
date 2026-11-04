<?php

use GamePlatform\Social\SocialLoginController;
use Illuminate\Support\Facades\Route;

// Sign in with Google, Facebook or GitHub.
Route::middleware(['web', 'guest', 'throttle:20,1'])->group(function () {
    Route::get('auth/{provider}/redirect', [SocialLoginController::class, 'redirect'])->name('social.redirect');
    Route::get('auth/{provider}/callback', [SocialLoginController::class, 'callback'])->name('social.callback');
});
