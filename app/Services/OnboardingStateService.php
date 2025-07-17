<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OnboardingStateService
{
    private const CACHE_KEY = 'onboarding_state';
    private const STEP_CACHE_KEY = 'onboarding_current_step';
    
    /**
     * Get the current onboarding step
     */
    public function getCurrentStep(): int
    {
        return Cache::get(self::STEP_CACHE_KEY, 1);
    }
    
    /**
     * Set the current onboarding step
     */
    public function setCurrentStep(int $step): void
    {
        if ($step >= 1 && $step <= 3) {
            Cache::forever(self::STEP_CACHE_KEY, $step);
            Log::info("Onboarding step set to: $step");
        }
    }
    
    /**
     * Mark a specific step as completed
     */
    public function markStepCompleted(int $step): void
    {
        if ($step >= 1 && $step <= 3) {
            $completedSteps = $this->getCompletedSteps();
            $completedSteps[$step] = true;
            
            Cache::forever(self::CACHE_KEY, $completedSteps);
            Log::info("Onboarding step $step marked as completed");
        }
    }
    
    /**
     * Reset a specific step (mark as incomplete)
     */
    public function resetStep(int $step): void
    {
        if ($step >= 1 && $step <= 3) {
            $completedSteps = $this->getCompletedSteps();
            $completedSteps[$step] = false;
            
            Cache::forever(self::CACHE_KEY, $completedSteps);
            Log::info("Onboarding step $step reset to incomplete");
        }
    }
    
    /**
     * Check if a specific step is completed
     */
    public function isStepCompleted(int $step): bool
    {
        $completedSteps = $this->getCompletedSteps();
        return $completedSteps[$step] ?? false;
    }
    
    /**
     * Get all completed steps
     */
    public function getCompletedSteps(): array
    {
        return Cache::get(self::CACHE_KEY, [
            1 => false, // API Key
            2 => false, // Screen Recording Permission
            3 => false, // GitHub Star
        ]);
    }
    
    /**
     * Check if onboarding is completely finished
     */
    public function isOnboardingComplete(): bool
    {
        $completedSteps = $this->getCompletedSteps();
        return $completedSteps[1] && $completedSteps[2] && $completedSteps[3];
    }
    
    /**
     * Get the next incomplete step
     */
    public function getNextIncompleteStep(): int
    {
        $completedSteps = $this->getCompletedSteps();
        
        for ($step = 1; $step <= 3; $step++) {
            if (!($completedSteps[$step] ?? false)) {
                return $step;
            }
        }
        
        return 3; // All steps completed, return last step
    }
    
    /**
     * Reset onboarding state (for testing or re-onboarding)
     */
    public function resetOnboarding(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::STEP_CACHE_KEY);
        Log::info("Onboarding state reset");
    }
    
    /**
     * Get onboarding status for API responses
     */
    public function getOnboardingStatus(): array
    {
        $completedSteps = $this->getCompletedSteps();
        $currentStep = $this->getCurrentStep();
        $isComplete = $this->isOnboardingComplete();
        
        return [
            'current_step' => $currentStep,
            'completed_steps' => $completedSteps,
            'is_complete' => $isComplete,
            'next_step' => $isComplete ? null : $this->getNextIncompleteStep(),
        ];
    }
    
    /**
     * Advanced to next step if current step is completed
     */
    public function advanceToNextStep(): int
    {
        $currentStep = $this->getCurrentStep();
        $nextStep = $this->getNextIncompleteStep();
        
        // If current step is completed, advance to next
        if ($this->isStepCompleted($currentStep) && $nextStep > $currentStep) {
            $this->setCurrentStep($nextStep);
            return $nextStep;
        }
        
        return $currentStep;
    }
    
    /**
     * Handle permission revocation scenario
     * Called when system permissions are revoked outside the app
     */
    public function handlePermissionRevocation(): void
    {
        // Reset step 2 (permission step) to incomplete
        $this->resetStep(2);
        
        // Set current step to 2 so user is taken back to permission screen
        $this->setCurrentStep(2);
        
        Log::info("Permission revocation detected - user redirected to permission step");
    }
    
    /**
     * Handle API key removal scenario
     * Called when API key is removed outside the app
     */
    public function handleApiKeyRemoval(): void
    {
        // Reset step 1 (API key step) to incomplete
        $this->resetStep(1);
        
        // Also reset step 2 and 3 since they depend on step 1
        $this->resetStep(2);
        $this->resetStep(3);
        
        // Set current step to 1 so user starts from beginning
        $this->setCurrentStep(1);
        
        Log::info("API key removal detected - user redirected to API key step");
    }
    
    /**
     * Handle incomplete onboarding scenario
     * Called when steps 1&2 are complete but step 3 is missing
     */
    public function handleIncompleteOnboarding(): void
    {
        // Ensure steps 1&2 are marked as completed
        $this->markStepCompleted(1);
        $this->markStepCompleted(2);
        
        // Set current step to 3 so user is taken to GitHub star step
        $this->setCurrentStep(3);
        
        Log::info("Incomplete onboarding detected - user redirected to GitHub star step");
    }
}