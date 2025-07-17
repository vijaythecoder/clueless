<?php

use Tests\Traits\MocksOnboarding;

uses(MocksOnboarding::class);

test('dashboard page is accessible', function () {
    // Mock complete onboarding flow (API key + permissions + completion)
    $this->mockCompletedOnboarding();
    
    $response = $this->get('/dashboard');
    $response->assertStatus(200);
});

test('realtime agent page is accessible', function () {
    // Mock complete onboarding flow (API key + permissions + completion)
    $this->mockCompletedOnboarding();
    
    $response = $this->get('/realtime-agent');
    $response->assertStatus(200);
});
