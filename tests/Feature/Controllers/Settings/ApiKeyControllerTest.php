<?php

use Illuminate\Support\Facades\Cache;
use Tests\Traits\MocksOpenAI;

uses(MocksOpenAI::class);

beforeEach(function () {
    Cache::flush();
});

test('api keys settings page can be viewed', function () {
    $response = $this->get('/settings/api-keys');

    $response->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->component('settings/ApiKeys')
            ->has('hasApiKey')
        );
});

test('api keys page shows when key exists in cache', function () {
    Cache::put('app_openai_api_key', mockApiKey());

    $response = $this->get('/settings/api-keys');

    $response->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->where('hasApiKey', true)
            ->where('isUsingEnvKey', false)
        );
});

test('api keys page shows when using environment key', function () {
    Config::set('openai.api_key', mockApiKey());

    $response = $this->get('/settings/api-keys');

    $response->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->where('hasApiKey', true)
            ->where('isUsingEnvKey', true)
        );
});

test('can update api key with valid key', function () {
    $apiKey = mockApiKey();
    $this->mockOpenAIModelsSuccess();

    $response = $this->put('/settings/api-keys', [
        'openai_api_key' => $apiKey,
    ]);

    $response->assertRedirect('/settings/api-keys')
        ->assertSessionHas('success', 'API key updated successfully.');

    expect(Cache::get('app_openai_api_key'))->toBe($apiKey);
});

test('cannot update api key with invalid key', function () {
    $this->mockOpenAIModelsFailure();

    $response = $this->put('/settings/api-keys', [
        'openai_api_key' => 'invalid-key',
    ]);

    $response->assertSessionHasErrors(['openai_api_key']);
    expect(Cache::has('app_openai_api_key'))->toBeFalse();
});

test('cannot update api key with short key', function () {
    $response = $this->put('/settings/api-keys', [
        'openai_api_key' => 'short',
    ]);

    $response->assertSessionHasErrors(['openai_api_key']);
});

test('cannot update api key without providing key', function () {
    $response = $this->put('/settings/api-keys', []);

    $response->assertSessionHasErrors(['openai_api_key']);
});

test('can delete api key', function () {
    Cache::put('app_openai_api_key', mockApiKey());

    $response = $this->delete('/settings/api-keys');

    $response->assertRedirect('/settings/api-keys')
        ->assertSessionHas('success', 'API key deleted successfully.');

    expect(Cache::has('app_openai_api_key'))->toBeFalse();
});

test('deleting non-existent api key still succeeds', function () {
    $response = $this->delete('/settings/api-keys');

    $response->assertRedirect('/settings/api-keys')
        ->assertSessionHas('success', 'API key deleted successfully.');
});

test('api key validation handles connection errors gracefully', function () {
    $this->mockHttpTimeout();

    $response = $this->put('/settings/api-keys', [
        'openai_api_key' => mockApiKey(),
    ]);

    $response->assertSessionHasErrors(['openai_api_key']);
    expect(Cache::has('app_openai_api_key'))->toBeFalse();
});

test('api key is properly validated with OpenAI before saving', function () {
    $apiKey = mockApiKey();

    Http::fake([
        'api.openai.com/v1/models' => Http::response(function ($request) use ($apiKey) {
            expect($request->header('Authorization')[0])->toBe("Bearer {$apiKey}");

            return [
                'object' => 'list',
                'data' => [['id' => 'gpt-4', 'object' => 'model']],
            ];
        }, 200),
    ]);

    $response = $this->put('/settings/api-keys', [
        'openai_api_key' => $apiKey,
    ]);

    $response->assertRedirect('/settings/api-keys');
});
