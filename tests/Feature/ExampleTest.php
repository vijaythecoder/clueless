<?php

use App\Services\ApiKeyService;

it('returns a successful response', function () {
    // Mock API key service to return true (API key exists)
    $mockApiKeyService = Mockery::mock(ApiKeyService::class);
    $mockApiKeyService->shouldReceive('hasApiKey')->andReturn(true);
    $this->app->instance(ApiKeyService::class, $mockApiKeyService);

    $response = $this->get('/');

    $response->assertStatus(200);
});
