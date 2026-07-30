<?php

use App\Models\SecureSetting;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Traits\MocksOpenAI;

uses(MocksOpenAI::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = new ApiKeyService;
    Config::set('openai.api_key', null);
    Cache::flush();
});

test('getApiKey returns stored secure setting when available', function () {
    $storedKey = mockApiKey();
    $this->service->setApiKey($storedKey);

    $result = $this->service->getApiKey();

    expect($result)->toBe($storedKey);
});

test('getApiKey falls back to config when no stored key exists', function () {
    $configKey = 'sk-config-key';
    Config::set('openai.api_key', $configKey);

    $result = $this->service->getApiKey();

    expect($result)->toBe($configKey);
});

test('getApiKey migrates and removes a legacy plaintext cache key', function () {
    $legacyKey = mockApiKey();
    Cache::forever('app_openai_api_key', $legacyKey);

    expect($this->service->getApiKey())->toBe($legacyKey)
        ->and(Cache::has('app_openai_api_key'))->toBeFalse()
        ->and(SecureSetting::where('key', SecureSetting::OPENAI_API_KEY)->value('value'))->toBe($legacyKey)
        ->and(DB::table('secure_settings')->value('value'))->not->toBe($legacyKey);
});

test('getApiKey returns null when no key available', function () {
    Config::set('openai.api_key', null);

    $result = $this->service->getApiKey();

    expect($result)->toBeNull();
});

test('setApiKey stores one encrypted database value', function () {
    $apiKey = mockApiKey();

    $this->service->setApiKey($apiKey);
    $this->service->setApiKey($apiKey.'-replacement');

    $rawValue = DB::table('secure_settings')
        ->where('key', SecureSetting::OPENAI_API_KEY)
        ->value('value');

    expect($rawValue)
        ->not->toBe($apiKey.'-replacement')
        ->and($rawValue)->not->toContain('replacement')
        ->and(SecureSetting::where('key', SecureSetting::OPENAI_API_KEY)->value('value'))
        ->toBe($apiKey.'-replacement')
        ->and(SecureSetting::where('key', SecureSetting::OPENAI_API_KEY)->count())
        ->toBe(1);
});

test('removeApiKey removes stored key', function () {
    $apiKey = mockApiKey();
    $this->service->setApiKey($apiKey);

    $this->service->removeApiKey();

    expect(SecureSetting::where('key', SecureSetting::OPENAI_API_KEY)->exists())->toBeFalse();
});

test('hasApiKey returns true when stored key exists', function () {
    $this->service->setApiKey(mockApiKey());

    $result = $this->service->hasApiKey();

    expect($result)->toBeTrue();
});

test('hasStoredApiKey only reports database-backed keys', function () {
    Config::set('openai.api_key', 'sk-config-key');

    expect($this->service->hasStoredApiKey())->toBeFalse();

    $this->service->setApiKey(mockApiKey());

    expect($this->service->hasStoredApiKey())->toBeTrue();
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

test('priority is stored key over config key', function () {
    $storedKey = 'sk-stored-key';
    $configKey = 'sk-config-key';

    $this->service->setApiKey($storedKey);
    Config::set('openai.api_key', $configKey);

    $result = $this->service->getApiKey();

    expect($result)->toBe($storedKey);
});
