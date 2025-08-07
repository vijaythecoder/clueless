<?php

use App\Http\Controllers\Settings\ApiKeyController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// No auth needed - single user desktop app
Route::get('settings/api-keys', [ApiKeyController::class, 'edit'])->name('api-keys.edit');
Route::put('settings/api-keys', [ApiKeyController::class, 'update'])->name('api-keys.update');
Route::delete('settings/api-keys', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

// API endpoint for saving API key (used by onboarding)
Route::post('/api/openai/api-key', [ApiKeyController::class, 'store'])->name('api.openai.api-key.store');

Route::get('settings/appearance', function () {
    return Inertia::render('settings/Appearance');
})->name('appearance');

Route::get('settings/recording', function () {
    return Inertia::render('settings/Recording');
})->name('recording');

// Redirect settings to API keys (most important setting)
Route::redirect('settings', '/settings/api-keys');
