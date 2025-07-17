<?php

use Tests\Traits\MocksOnboarding;

uses(MocksOnboarding::class);

it('returns a successful response', function () {
    // Mock complete onboarding flow (API key + permissions + completion)
    $this->mockCompletedOnboarding();
    
    $response = $this->get('/');

    $response->assertStatus(200);
});
