<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return redirect()->route('login');
})->name('home');

Route::get('/home', function () {
    $user = request()->user();

    if (! $user) {
        return redirect()->route('home');
    }

    return $user->isAdmin()
        ? redirect()->route('dashboard')
        : redirect()->route('client.home');
})->middleware('auth')->name('redirect.home');

Route::middleware(['auth', 'verified', 'admin'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

Route::middleware(['auth', 'client'])->group(function () {
    Route::get('client', function () {
        return Inertia::render('client/home');
    })->name('client.home');
});

require __DIR__.'/settings.php';
