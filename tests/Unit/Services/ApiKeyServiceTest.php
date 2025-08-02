<?php

use App\Services\ApiKeyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\Traits\MocksOpenAI;

uses(MocksOpenAI::class);

beforeEach(function () {
    $this->service = new ApiKeyService;
    Cache::flush(); // Clear cache before each test
});

test('getApiKey returns cached key when available', function () {
    $cachedKey = mockApiKey();
    Cache::put('app_openai_api_key', $cachedKey);

    $result = $this->service->getApiKey();

    expect($result)->toBe($cachedKey);
});

test('getApiKey falls back to config when no cached key', function () {
    $configKey = 'sk-config-key';
    Config::set('openai.api_key', $configKey);

    $result = $this->service->getApiKey();

    expect($result)->toBe($configKey);
});

test('getApiKey returns null when no key available', function () {
    Config::set('openai.api_key', null);

    $result = $this->service->getApiKey();

    expect($result)->toBeNull();
});

test('setApiKey stores key in cache permanently', function () {
    $apiKey = mockApiKey();

    $this->service->setApiKey($apiKey);

    expect(Cache::get('app_openai_api_key'))->toBe($apiKey);
});

test('removeApiKey removes key from cache', function () {
    $apiKey = mockApiKey();
    Cache::put('app_openai_api_key', $apiKey);

    $this->service->removeApiKey();

    expect(Cache::has('app_openai_api_key'))->toBeFalse();
});

test('hasApiKey returns true when cached key exists', function () {
    Cache::put('app_openai_api_key', mockApiKey());

    $result = $this->service->hasApiKey();

    expect($result)->toBeTrue();
});

test('hasApiKey returns true when config key exists', function () {
    Config::set('openai.api_key', 'sk-config-key');

    $result = $this->service->hasApiKey();

    expect($result)->toBeTrue();
});

test('hasApiKey returns false when no key exists', function () {
    Config::set('openai.api_key', null);

    $result = $this->service->hasApiKey();

    expect($result)->toBeFalse();
});

test('hasApiKey returns false for empty string key', function () {
    Cache::put('app_openai_api_key', '');
    Config::set('openai.api_key', null); // Ensure no fallback

    $result = $this->service->hasApiKey();

    expect($result)->toBeFalse();
});

test('validateApiKey returns true for valid key', function () {
    $this->mockOpenAIModelsSuccess();

    $result = $this->service->validateApiKey(mockApiKey());

    expect($result)->toBeTrue();
});

test('validateApiKey returns false for invalid key', function () {
    $this->mockOpenAIModelsFailure();

    $result = $this->service->validateApiKey('invalid-key');

    expect($result)->toBeFalse();
});

test('validateApiKey returns false on connection error', function () {
    $this->mockHttpTimeout();

    $result = $this->service->validateApiKey(mockApiKey());

    expect($result)->toBeFalse();
});

test('priority is cached key over config key', function () {
    $cachedKey = 'sk-cached-key';
    $configKey = 'sk-config-key';

    Cache::put('app_openai_api_key', $cachedKey);
    Config::set('openai.api_key', $configKey);

    $result = $this->service->getApiKey();

    expect($result)->toBe($cachedKey);
});
