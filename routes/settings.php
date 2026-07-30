<?php

use App\Http\Controllers\McpToolConfigController;
use App\Http\Controllers\Settings\ApiKeyController;
use App\Http\Controllers\Settings\RecallSettingsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// No auth needed - single user desktop app
Route::get('settings/api-keys', [ApiKeyController::class, 'edit'])->name('api-keys.edit');
Route::put('settings/api-keys', [ApiKeyController::class, 'update'])->name('api-keys.update');
Route::delete('settings/api-keys', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

Route::get('settings/recall', [RecallSettingsController::class, 'edit'])->name('recall.edit');
Route::put('settings/recall', [RecallSettingsController::class, 'update'])->name('recall.update');

// API endpoint for saving API key (used by onboarding)
Route::post('/api/openai/api-key', [ApiKeyController::class, 'store'])->name('api.openai.api-key.store');

Route::get('settings/appearance', function () {
    return Inertia::render('settings/Appearance');
})->name('appearance');

Route::get('settings/sales-tools', [McpToolConfigController::class, 'edit'])->name('sales-tools.edit');
Route::get('/api/sales-tools', [McpToolConfigController::class, 'index'])->name('sales-tools.index');
Route::post('/api/sales-tools', [McpToolConfigController::class, 'store'])->name('sales-tools.store');
Route::put('/api/sales-tools/{mcpToolConfig}', [McpToolConfigController::class, 'update'])->name('sales-tools.update');
Route::post('/api/sales-tools/{mcpToolConfig}/test', [McpToolConfigController::class, 'test'])->name('sales-tools.test');
Route::delete('/api/sales-tools/{mcpToolConfig}', [McpToolConfigController::class, 'destroy'])->name('sales-tools.destroy');

// Redirect settings to API keys (most important setting)
Route::redirect('settings', '/settings/api-keys');
