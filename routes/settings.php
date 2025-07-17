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

// Onboarding State API Routes
Route::prefix('api/onboarding')->group(function () {
    Route::get('/status', function () {
        $onboardingService = app(\App\Services\OnboardingStateService::class);
        return response()->json($onboardingService->getOnboardingStatus());
    })->name('api.onboarding.status');
    
    Route::post('/step', function (\Illuminate\Http\Request $request) {
        $onboardingService = app(\App\Services\OnboardingStateService::class);
        $step = $request->input('step');
        $markCompleted = $request->input('mark_completed', false);
        
        if ($step >= 1 && $step <= 3) {
            $onboardingService->setCurrentStep($step);
            
            if ($markCompleted) {
                $onboardingService->markStepCompleted($step);
            }
            
            return response()->json([
                'success' => true,
                'current_step' => $step,
                'status' => $onboardingService->getOnboardingStatus()
            ]);
        }
        
        return response()->json(['error' => 'Invalid step number'], 400);
    })->name('api.onboarding.step');
    
    Route::post('/complete', function () {
        $onboardingService = app(\App\Services\OnboardingStateService::class);
        
        // Mark all steps as completed
        $onboardingService->markStepCompleted(1);
        $onboardingService->markStepCompleted(2);
        $onboardingService->markStepCompleted(3);
        
        return response()->json([
            'success' => true,
            'message' => 'Onboarding completed successfully',
            'status' => $onboardingService->getOnboardingStatus()
        ]);
    })->name('api.onboarding.complete');
});

Route::get('settings/appearance', function () {
    return Inertia::render('settings/Appearance');
})->name('appearance');

// Redirect settings to API keys (most important setting)
Route::redirect('settings', '/settings/api-keys');
