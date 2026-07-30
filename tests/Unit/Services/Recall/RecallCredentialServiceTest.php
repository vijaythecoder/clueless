<?php

use App\Models\SecureSetting;
use App\Services\Recall\RecallCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('services.recall.api_key', null);
    Config::set('services.recall.webhook_secret', null);
    Config::set('services.recall.region', 'us-west-2');
    Config::set('services.recall.webhook_url', null);

    $this->service = app(RecallCredentialService::class);
});

it('reads encrypted Recall credentials from secure settings before env fallbacks', function () {
    SecureSetting::create(['key' => 'recall_api_key', 'value' => 'recall-stored-api-key']);
    SecureSetting::create(['key' => 'recall_webhook_secret', 'value' => 'whsec_stored_webhook_secret']);
    Config::set('services.recall.api_key', 'recall-environment-api-key');
    Config::set('services.recall.webhook_secret', 'whsec_environment_webhook_secret');

    expect($this->service->apiKey())->toBe('recall-stored-api-key')
        ->and($this->service->webhookSecret())->toBe('whsec_stored_webhook_secret')
        ->and(DB::table('secure_settings')->where('key', 'recall_api_key')->value('value'))->not->toContain('recall-stored-api-key')
        ->and(DB::table('secure_settings')->where('key', 'recall_webhook_secret')->value('value'))->not->toContain('whsec_stored_webhook_secret');
});

it('falls back to configured environment credentials', function () {
    Config::set('services.recall.api_key', 'recall-environment-api-key');
    Config::set('services.recall.webhook_secret', 'whsec_environment_webhook_secret');

    expect($this->service->apiKey())->toBe('recall-environment-api-key')
        ->and($this->service->webhookSecret())->toBe('whsec_environment_webhook_secret')
        ->and($this->service->isConfigured())->toBeTrue();
});

it('reports that Recall is not configured when either secret is missing', function () {
    Config::set('services.recall.api_key', 'recall-environment-api-key');

    expect($this->service->isConfigured())->toBeFalse();

    Config::set('services.recall.api_key', null);
    Config::set('services.recall.webhook_secret', 'whsec_environment_webhook_secret');

    expect($this->service->isConfigured())->toBeFalse();
});

it('throws a safe domain exception when a required credential is missing', function () {
    expect(fn () => $this->service->apiKey())
        ->toThrow(DomainException::class, 'Recall API key is not configured');
});

it('never exposes credential values from its status payload', function () {
    SecureSetting::create(['key' => 'recall_api_key', 'value' => 'recall-stored-api-key']);
    SecureSetting::create(['key' => 'recall_webhook_secret', 'value' => 'whsec_stored_webhook_secret']);
    Config::set('services.recall.region', 'us-east-1');
    Config::set('services.recall.webhook_url', 'https://clueless.test/webhooks/recall');

    expect($this->service->status())->toBe([
        'configured' => true,
        'region' => 'us-east-1',
        'webhook_url' => 'https://clueless.test/webhooks/recall',
    ]);
});
