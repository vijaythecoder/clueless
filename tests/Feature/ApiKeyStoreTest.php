<?php

namespace Tests\Feature;

use App\Services\ApiKeyService;
use Mockery;
use Tests\TestCase;

class ApiKeyStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_stores_valid_api_key_successfully(): void
    {
        $mockApiKeyService = Mockery::mock(ApiKeyService::class);
        $this->app->instance(ApiKeyService::class, $mockApiKeyService);

        $validApiKey = 'sk-1234567890abcdef1234567890abcdef1234567890abcdef';

        $mockApiKeyService->shouldReceive('validateApiKey')
            ->once()
            ->with($validApiKey)
            ->andReturn(true);

        $mockApiKeyService->shouldReceive('setApiKey')
            ->once()
            ->with($validApiKey);

        $response = $this->postJson('/api/openai/api-key', [
            'api_key' => $validApiKey,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'API key saved successfully.',
            ]);
    }

    public function test_rejects_invalid_api_key(): void
    {
        $mockApiKeyService = Mockery::mock(ApiKeyService::class);
        $this->app->instance(ApiKeyService::class, $mockApiKeyService);

        $invalidApiKey = 'sk-1234567890abcdef1234567890abcdef1234567890invalid';

        $mockApiKeyService->shouldReceive('validateApiKey')
            ->once()
            ->with($invalidApiKey)
            ->andReturn(false);

        $mockApiKeyService->shouldNotReceive('setApiKey');

        $response = $this->postJson('/api/openai/api-key', [
            'api_key' => $invalidApiKey,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'The provided API key is invalid. Please check and try again.',
            ]);
    }

    public function test_validates_required_api_key_field(): void
    {
        $response = $this->postJson('/api/openai/api-key', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['api_key']);
    }

    public function test_validates_api_key_minimum_length(): void
    {
        $response = $this->postJson('/api/openai/api-key', [
            'api_key' => 'sk-short',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['api_key']);
    }

    public function test_validates_api_key_is_string(): void
    {
        $response = $this->postJson('/api/openai/api-key', [
            'api_key' => 123456,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['api_key']);
    }

    public function test_handles_api_key_service_exception(): void
    {
        $mockApiKeyService = Mockery::mock(ApiKeyService::class);
        $this->app->instance(ApiKeyService::class, $mockApiKeyService);

        $validApiKey = 'sk-1234567890abcdef1234567890abcdef1234567890abcdef';

        $mockApiKeyService->shouldReceive('validateApiKey')
            ->once()
            ->with($validApiKey)
            ->andThrow(new \Exception('Service error'));

        $response = $this->postJson('/api/openai/api-key', [
            'api_key' => $validApiKey,
        ]);

        // Controller doesn't handle exceptions, so it returns 500
        $response->assertStatus(500);
    }

    public function test_api_key_endpoint_accessible_without_existing_api_key(): void
    {
        // This test ensures the middleware allows access to the API key store endpoint
        // even when no API key is configured

        $mockApiKeyService = Mockery::mock(ApiKeyService::class);
        $this->app->instance(ApiKeyService::class, $mockApiKeyService);

        $validApiKey = 'sk-1234567890abcdef1234567890abcdef1234567890abcdef';

        $mockApiKeyService->shouldReceive('validateApiKey')
            ->once()
            ->andReturn(true);

        $mockApiKeyService->shouldReceive('setApiKey')
            ->once();

        $response = $this->postJson('/api/openai/api-key', [
            'api_key' => $validApiKey,
        ]);

        $response->assertStatus(200);
    }
}
