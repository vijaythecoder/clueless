<?php

use App\Services\ApiKeyService;

test('dashboard page is accessible', function () {
    // Mock API key service to return true (API key exists)
    $mockApiKeyService = Mockery::mock(ApiKeyService::class);
    $mockApiKeyService->shouldReceive('hasApiKey')->andReturn(true);
    $this->app->instance(ApiKeyService::class, $mockApiKeyService);

    $response = $this->get('/dashboard');
    $response->assertStatus(200);
});

test('realtime agent page is accessible', function () {
    // Mock API key service to return true (API key exists)
    $mockApiKeyService = Mockery::mock(ApiKeyService::class);
    $mockApiKeyService->shouldReceive('hasApiKey')->andReturn(true);
    $this->app->instance(ApiKeyService::class, $mockApiKeyService);

    $response = $this->get('/realtime-agent');
    $response->assertStatus(200);
});
