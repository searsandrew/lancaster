<?php

use App\Http\Controllers\Auth\MicrosoftAuthenticationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('auth/microsoft', [MicrosoftAuthenticationController::class, 'redirect'])
        ->name('microsoft.redirect');
    Route::get('auth/microsoft/callback', [MicrosoftAuthenticationController::class, 'callback'])
        ->name('microsoft.callback');
});

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

require __DIR__.'/settings.php';
