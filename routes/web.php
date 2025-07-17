<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    // Auto-login middleware handles authentication
    // If authenticated, redirect to dashboard
    if (Auth::check()) {
        return redirect()->route('dashboard');
    }
    
    return Inertia::render('Welcome');
})->name('home');

// Onboarding Route
Route::get('/onboarding', function () {
    return Inertia::render('Onboarding');
})->name('onboarding');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->name('dashboard');


// NativePHP Desktop Routes
Route::get('/realtime-agent', function () {
    return Inertia::render('RealtimeAgent/Main');
})->name('realtime-agent');

Route::get('/realtime-agent/settings', function () {
    return Inertia::render('RealtimeAgent/Settings');
})->name('realtime-agent.settings');

// Realtime API Routes
Route::post('/api/realtime/ephemeral-key', [\App\Http\Controllers\RealtimeController::class, 'generateEphemeralKey'])
    ->name('realtime.ephemeral-key');

// API Key Status Route
Route::get('/api/openai/status', function () {
    $apiKeyService = app(\App\Services\ApiKeyService::class);
    return response()->json([
        'hasApiKey' => $apiKeyService->hasApiKey(),
    ]);
})->name('api.openai.status');


// Screen Recording Permission API Routes
Route::prefix('api/permissions')->group(function () {
    Route::get('/screen-recording/status', function () {
        $permissionService = app(\App\Services\AudioCapturePermissionService::class);
        return response()->json($permissionService->getPermissionStatus());
    })->name('api.permissions.screen-recording.status');
    
    Route::post('/screen-recording/request', function () {
        $permissionService = app(\App\Services\AudioCapturePermissionService::class);
        return response()->json($permissionService->requestScreenRecordingPermission());
    })->name('api.permissions.screen-recording.request');
    
    Route::get('/screen-recording/check', function () {
        $permissionService = app(\App\Services\AudioCapturePermissionService::class);
        return response()->json($permissionService->checkScreenRecordingPermission());
    })->name('api.permissions.screen-recording.check');
});

// Open external URL in default browser (for NativePHP)
Route::post('/api/open-external', function (\Illuminate\Http\Request $request) {
    $url = $request->input('url');
    
    // Validate URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return response()->json(['error' => 'Invalid URL'], 400);
    }
    
    // Use NativePHP Shell to open in default browser
    \Native\Laravel\Facades\Shell::openExternal($url);
    
    return response()->json(['success' => true]);
})->name('api.open-external');

// Template Routes
Route::get('/templates', [\App\Http\Controllers\TemplateController::class, 'index'])
    ->name('templates.index');
Route::post('/templates', [\App\Http\Controllers\TemplateController::class, 'store'])
    ->name('templates.store');
Route::put('/templates/{template}', [\App\Http\Controllers\TemplateController::class, 'update'])
    ->name('templates.update');
Route::delete('/templates/{template}', [\App\Http\Controllers\TemplateController::class, 'destroy'])
    ->name('templates.destroy');
Route::post('/templates/{template}/use', [\App\Http\Controllers\TemplateController::class, 'incrementUsage'])
    ->name('templates.use');

// Template Form Routes
Route::get('/realtime-agent/templates/create', [\App\Http\Controllers\TemplateController::class, 'create'])
    ->name('templates.create');
Route::get('/realtime-agent/templates/{template}/edit', [\App\Http\Controllers\TemplateController::class, 'edit'])
    ->name('templates.edit');

// Conversation Routes (No auth required for desktop app)
Route::post('/conversations', [\App\Http\Controllers\ConversationController::class, 'store'])
    ->name('conversations.store');
Route::get('/conversations', [\App\Http\Controllers\ConversationController::class, 'index'])
    ->name('conversations.index');
Route::get('/conversations/{session}', [\App\Http\Controllers\ConversationController::class, 'show'])
    ->name('conversations.show');
Route::post('/conversations/{session}/end', [\App\Http\Controllers\ConversationController::class, 'end'])
    ->name('conversations.end');
Route::post('/conversations/{session}/transcript', [\App\Http\Controllers\ConversationController::class, 'saveTranscript'])
    ->name('conversations.transcript');
Route::post('/conversations/{session}/transcripts', [\App\Http\Controllers\ConversationController::class, 'saveBatchTranscripts'])
    ->name('conversations.transcripts');
Route::post('/conversations/{session}/insight', [\App\Http\Controllers\ConversationController::class, 'saveInsight'])
    ->name('conversations.insight');
Route::post('/conversations/{session}/insights', [\App\Http\Controllers\ConversationController::class, 'saveBatchInsights'])
    ->name('conversations.insights');
Route::patch('/conversations/{session}/notes', [\App\Http\Controllers\ConversationController::class, 'updateNotes'])
    ->name('conversations.notes');
Route::patch('/conversations/{session}/title', [\App\Http\Controllers\ConversationController::class, 'updateTitle'])
    ->name('conversations.title');
Route::delete('/conversations/{session}', [\App\Http\Controllers\ConversationController::class, 'destroy'])
    ->name('conversations.destroy');

// Variables Page Route
Route::get('/variables', function () {
    return Inertia::render('Variables/Index');
})->name('variables.page');

// Variable Management API Routes
Route::prefix('api/variables')->group(function () {
    Route::get('/', [\App\Http\Controllers\VariableController::class, 'index'])
        ->name('variables.index');
    Route::post('/', [\App\Http\Controllers\VariableController::class, 'store'])
        ->name('variables.store');
    Route::put('/{key}', [\App\Http\Controllers\VariableController::class, 'update'])
        ->name('variables.update');
    Route::delete('/{key}', [\App\Http\Controllers\VariableController::class, 'destroy'])
        ->name('variables.destroy');
    Route::post('/import', [\App\Http\Controllers\VariableController::class, 'import'])
        ->name('variables.import');
    Route::get('/export', [\App\Http\Controllers\VariableController::class, 'export'])
        ->name('variables.export');
    Route::post('/preview', [\App\Http\Controllers\VariableController::class, 'preview'])
        ->name('variables.preview');
    Route::get('/usage', [\App\Http\Controllers\VariableController::class, 'usage'])
        ->name('variables.usage');
    Route::post('/seed-defaults', [\App\Http\Controllers\VariableController::class, 'seedDefaults'])
        ->name('variables.seed');
});

require __DIR__.'/settings.php';
