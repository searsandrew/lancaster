<?php

use App\Http\Controllers\Auth\MicrosoftAuthenticationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('auth/microsoft', [MicrosoftAuthenticationController::class, 'redirect'])
        ->name('microsoft.redirect');
    Route::get('auth/microsoft/callback', [MicrosoftAuthenticationController::class, 'callback'])
        ->name('microsoft.callback');
});

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');
    });

require __DIR__.'/settings.php';
