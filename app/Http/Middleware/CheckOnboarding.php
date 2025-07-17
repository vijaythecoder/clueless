<?php

namespace App\Http\Middleware;

use App\Services\ApiKeyService;
use App\Services\OnboardingStateService;
use App\Services\AudioCapturePermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckOnboarding
{
    protected ApiKeyService $apiKeyService;
    protected OnboardingStateService $onboardingService;
    protected AudioCapturePermissionService $permissionService;

    public function __construct(
        ApiKeyService $apiKeyService, 
        OnboardingStateService $onboardingService,
        AudioCapturePermissionService $permissionService
    ) {
        $this->apiKeyService = $apiKeyService;
        $this->onboardingService = $onboardingService;
        $this->permissionService = $permissionService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Routes that should be accessible without completed onboarding
        $excludedRoutes = [
            'onboarding',
            'api-keys.edit',
            'api-keys.update',
            'api-keys.destroy',
            'api.openai.status',
            'api.openai.api-key.store',
            'api.onboarding.status',
            'api.onboarding.step',
            'api.onboarding.complete',
            'api.permissions.screen-recording.status',
            'api.permissions.screen-recording.request',
            'api.permissions.screen-recording.check',
            'api.open-external',
            'appearance',
        ];

        // Skip check for excluded routes
        if ($request->route() && in_array($request->route()->getName(), $excludedRoutes)) {
            return $next($request);
        }

        // Skip if API request (they handle their own errors)
        if ($request->is('api/*')) {
            return $next($request);
        }

        // Check if API key exists (Step 1)
        $hasApiKey = $this->apiKeyService->hasApiKey();
        
        // Check current system permissions (Step 2) - Always check real system state
        $permissionCheck = $this->permissionService->checkScreenRecordingPermission();
        $hasPermission = $permissionCheck['granted'] ?? false;
        
        // Check if complete onboarding is finished (Step 3)
        $isOnboardingComplete = $this->onboardingService->isOnboardingComplete();
        
        // If any step is missing, redirect to onboarding
        if (!$hasApiKey || !$hasPermission || !$isOnboardingComplete) {
            // If not on onboarding page, redirect to onboarding
            if ($request->route() && $request->route()->getName() !== 'onboarding') {
                // Update onboarding state based on what's missing
                if (!$hasApiKey) {
                    // No API key - handle API key removal
                    $this->onboardingService->handleApiKeyRemoval();
                } else if (!$hasPermission) {
                    // API key exists but no permission - handle permission revocation
                    $this->onboardingService->handlePermissionRevocation();
                } else if (!$isOnboardingComplete) {
                    // Steps 1&2 complete but step 3 incomplete - handle incomplete onboarding
                    $this->onboardingService->handleIncompleteOnboarding();
                }
                
                return redirect()->route('onboarding');
            }
        }

        return $next($request);
    }
}