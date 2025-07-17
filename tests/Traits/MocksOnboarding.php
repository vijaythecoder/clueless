<?php

namespace Tests\Traits;

use App\Services\ApiKeyService;
use App\Services\AudioCapturePermissionService;
use App\Services\OnboardingStateService;
use Mockery;

trait MocksOnboarding
{
    /**
     * Mock all onboarding-related services to allow access to protected routes
     * This mocks the complete onboarding flow (API key + permissions + completion)
     */
    protected function mockCompletedOnboarding(): void
    {
        // Mock ApiKeyService - Step 1 (API Key)
        $mockApiKeyService = Mockery::mock(ApiKeyService::class);
        $mockApiKeyService->shouldReceive('hasApiKey')->andReturn(true);
        $this->app->instance(ApiKeyService::class, $mockApiKeyService);

        // Mock AudioCapturePermissionService - Step 2 (Screen Recording Permission)
        $mockPermissionService = Mockery::mock(AudioCapturePermissionService::class);
        $mockPermissionService->shouldReceive('checkScreenRecordingPermission')->andReturn([
            'granted' => true,
            'error' => null,
            'needs_rebuild' => false
        ]);
        $this->app->instance(AudioCapturePermissionService::class, $mockPermissionService);

        // Mock OnboardingStateService - Step 3 (Onboarding Completion)
        $mockOnboardingService = Mockery::mock(OnboardingStateService::class);
        $mockOnboardingService->shouldReceive('isOnboardingComplete')->andReturn(true);
        $this->app->instance(OnboardingStateService::class, $mockOnboardingService);
    }

    /**
     * Mock incomplete onboarding (missing API key) to test redirection to onboarding
     */
    protected function mockIncompleteOnboarding(): void
    {
        // Mock ApiKeyService - No API Key
        $mockApiKeyService = Mockery::mock(ApiKeyService::class);
        $mockApiKeyService->shouldReceive('hasApiKey')->andReturn(false);
        $this->app->instance(ApiKeyService::class, $mockApiKeyService);

        // Mock AudioCapturePermissionService
        $mockPermissionService = Mockery::mock(AudioCapturePermissionService::class);
        $this->app->instance(AudioCapturePermissionService::class, $mockPermissionService);

        // Mock OnboardingStateService with handleApiKeyRemoval expectation
        $mockOnboardingService = Mockery::mock(OnboardingStateService::class);
        $mockOnboardingService->shouldReceive('handleApiKeyRemoval')->once();
        $this->app->instance(OnboardingStateService::class, $mockOnboardingService);
    }

    /**
     * Mock missing permissions scenario
     */
    protected function mockMissingPermissions(): void
    {
        // Mock ApiKeyService - Has API Key
        $mockApiKeyService = Mockery::mock(ApiKeyService::class);
        $mockApiKeyService->shouldReceive('hasApiKey')->andReturn(true);
        $this->app->instance(ApiKeyService::class, $mockApiKeyService);

        // Mock AudioCapturePermissionService - No Permission
        $mockPermissionService = Mockery::mock(AudioCapturePermissionService::class);
        $mockPermissionService->shouldReceive('checkScreenRecordingPermission')->andReturn([
            'granted' => false,
            'error' => null,
            'needs_rebuild' => false
        ]);
        $this->app->instance(AudioCapturePermissionService::class, $mockPermissionService);

        // Mock OnboardingStateService with handlePermissionRevocation expectation
        $mockOnboardingService = Mockery::mock(OnboardingStateService::class);
        $mockOnboardingService->shouldReceive('handlePermissionRevocation')->once();
        $this->app->instance(OnboardingStateService::class, $mockOnboardingService);
    }

    /**
     * Mock incomplete onboarding (steps 1&2 complete but step 3 missing)
     */
    protected function mockIncompleteGithubStep(): void
    {
        // Mock ApiKeyService - Has API Key
        $mockApiKeyService = Mockery::mock(ApiKeyService::class);
        $mockApiKeyService->shouldReceive('hasApiKey')->andReturn(true);
        $this->app->instance(ApiKeyService::class, $mockApiKeyService);

        // Mock AudioCapturePermissionService - Has Permission
        $mockPermissionService = Mockery::mock(AudioCapturePermissionService::class);
        $mockPermissionService->shouldReceive('checkScreenRecordingPermission')->andReturn([
            'granted' => true,
            'error' => null,
            'needs_rebuild' => false
        ]);
        $this->app->instance(AudioCapturePermissionService::class, $mockPermissionService);

        // Mock OnboardingStateService - Not Complete
        $mockOnboardingService = Mockery::mock(OnboardingStateService::class);
        $mockOnboardingService->shouldReceive('isOnboardingComplete')->andReturn(false);
        $mockOnboardingService->shouldReceive('handleIncompleteOnboarding')->once();
        $this->app->instance(OnboardingStateService::class, $mockOnboardingService);
    }
}