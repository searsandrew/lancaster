<?php

use App\Http\Controllers\Auth\MicrosoftAuthenticationController;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::register')->name('home');
Route::livewire('leaderboard', 'pages::leaderboard')->name('leaderboard');

Route::middleware('guest')->group(function () {
    Route::get('auth/microsoft', [MicrosoftAuthenticationController::class, 'redirect'])
        ->name('microsoft.redirect');
    Route::get('auth/microsoft/callback', [MicrosoftAuthenticationController::class, 'callback'])
        ->name('microsoft.callback');
});

Route::livewire('dashboard', 'pages::dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::redirect('quiz', 'dashboard')
    ->middleware(['auth', 'verified']);

Route::livewire('shows', 'pages::shows.index')
    ->middleware(['auth', 'verified'])
    ->name('shows.index');

Route::livewire('shows/{show}/edit', 'pages::shows.edit')
    ->middleware(['auth', 'verified'])
    ->name('shows.edit');

require __DIR__.'/settings.php';
