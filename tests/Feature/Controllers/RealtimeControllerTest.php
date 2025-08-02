<?php

use Illuminate\Support\Facades\Cache;
use Tests\Traits\MocksOpenAI;

uses(MocksOpenAI::class);

beforeEach(function () {
    Cache::flush();
});

test('generateEphemeralKey returns success with valid API key', function () {
    // Arrange
    Cache::put('app_openai_api_key', mockApiKey());
    $this->mockEphemeralKeySuccess();

    // Act
    $response = $this->postJson('/api/realtime/ephemeral-key');

    // Assert
    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'ephemeralKey',
            'expiresAt',
            'sessionId',
            'model',
        ])
        ->assertJson([
            'status' => 'success',
            'model' => 'gpt-4o-mini-realtime-preview-2024-12-17',
        ]);

    expect($response->json('ephemeralKey'))->toStartWith('ek_');
});

test('generateEphemeralKey returns error when no API key configured', function () {
    // Arrange
    Config::set('openai.api_key', null); // Ensure no config key

    // Act
    $response = $this->postJson('/api/realtime/ephemeral-key');

    // Assert
    $response->assertStatus(422)
        ->assertJson([
            'status' => 'error',
            'message' => 'OpenAI API key not configured',
        ]);
});

test('generateEphemeralKey returns error when OpenAI API fails', function () {
    // Arrange
    Cache::put('app_openai_api_key', mockApiKey());
    $this->mockEphemeralKeyFailure();

    // Act
    $response = $this->postJson('/api/realtime/ephemeral-key');

    // Assert
    $response->assertStatus(500)
        ->assertJson([
            'status' => 'error',
            'message' => 'Failed to generate ephemeral key',
        ]);
});

test('generateEphemeralKey returns error when response structure is invalid', function () {
    // Arrange
    Cache::put('app_openai_api_key', mockApiKey());
    $this->mockEphemeralKeyInvalidResponse();

    // Act
    $response = $this->postJson('/api/realtime/ephemeral-key');

    // Assert
    $response->assertStatus(500)
        ->assertJson([
            'status' => 'error',
            'message' => 'Failed to generate ephemeral key',
        ]);
});

test('generateEphemeralKey accepts custom voice parameter', function () {
    // Arrange
    Cache::put('app_openai_api_key', mockApiKey());
    Http::fake([
        'api.openai.com/v1/realtime/sessions' => function ($request) {
            expect($request->data()['voice'])->toBe('nova');

            return Http::response(mockEphemeralKeyResponse());
        },
    ]);

    // Act
    $response = $this->postJson('/api/realtime/ephemeral-key', [
        'voice' => 'nova',
    ]);

    // Assert
    $response->assertStatus(200);
});

test('generateEphemeralKey uses default voice when not provided', function () {
    // Arrange
    Cache::put('app_openai_api_key', mockApiKey());
    Http::fake([
        'api.openai.com/v1/realtime/sessions' => function ($request) {
            expect($request->data()['voice'])->toBe('alloy');

            return Http::response(mockEphemeralKeyResponse());
        },
    ]);

    // Act
    $response = $this->postJson('/api/realtime/ephemeral-key');

    // Assert
    $response->assertStatus(200);
});

test('generateEphemeralKey handles connection timeout gracefully', function () {
    // Arrange
    Cache::put('app_openai_api_key', mockApiKey());
    $this->mockHttpTimeout();

    // Act
    $response = $this->postJson('/api/realtime/ephemeral-key');

    // Assert
    $response->assertStatus(500)
        ->assertJson([
            'status' => 'error',
            'message' => 'Failed to generate ephemeral key',
        ]);
});

test('generateEphemeralKey uses API key from config when cache is empty', function () {
    // Arrange
    Config::set('openai.api_key', mockApiKey());
    $this->mockEphemeralKeySuccess();

    // Act
    $response = $this->postJson('/api/realtime/ephemeral-key');

    // Assert
    $response->assertStatus(200)
        ->assertJson(['status' => 'success']);
});
