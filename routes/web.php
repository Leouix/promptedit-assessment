<?php

use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'generations.create' : 'login'));

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('/generations/new', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('/generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::post('/credits/buy', [CreditController::class, 'buy'])->name('credits.buy');

    Route::get('/admin/report', [GenerationController::class, 'adminReport'])->name('admin.report');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Pixelforge has no CSRF token, so this route is excluded from CSRF
// verification in bootstrap/app.php and sits in the web group only to get
// its middleware stack otherwise untouched.
Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)
    ->name('webhooks.pixelforge');

require __DIR__.'/auth.php';
