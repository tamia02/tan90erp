<?php

use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Keep the role picker reachable even when a browser has an old authenticated
// session cookie. The role buttons intentionally switch the active workspace.
Volt::route('login', 'pages.auth.login')
    ->name('login');

Route::middleware('guest')->group(function () {
    // No self-registration route — Admin creates every account (User
    // Management screen), matching the client's requirement that each
    // role is admin-provisioned, not self-signed-up.
    Volt::route('forgot-password', 'pages.auth.forgot-password')
        ->name('password.request');

    Volt::route('reset-password/{token}', 'pages.auth.reset-password')
        ->name('password.reset');
});

Route::middleware('auth')->group(function () {
    Volt::route('verify-email', 'pages.auth.verify-email')
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Volt::route('confirm-password', 'pages.auth.confirm-password')
        ->name('password.confirm');
});
