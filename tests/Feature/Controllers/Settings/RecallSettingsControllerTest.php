<?php

use App\Models\SecureSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Config::set('services.recall.api_key', null);
    Config::set('services.recall.webhook_secret', null);
    Config::set('services.recall.region', 'us-west-2');
    Config::set('services.recall.webhook_url', null);
});

it('renders Recall settings without exposing secrets', function () {
    SecureSetting::create(['key' => 'recall_api_key', 'value' => 'recall-stored-api-key']);
    SecureSetting::create(['key' => 'recall_webhook_secret', 'value' => 'whsec_stored_webhook_secret']);

    $this->get('/settings/recall')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Recall')
            ->where('has_api_key', true)
            ->where('has_webhook_secret', true)
            ->where('configured', true)
            ->where('region', 'us-west-2')
            ->where('webhook_url', null)
            ->missing('api_key')
            ->missing('webhook_secret')
        );
});

it('stores valid Recall credentials encrypted', function () {
    $apiKey = 'recall-'.str_repeat('a', 24);
    $webhookSecret = 'whsec_'.str_repeat('b', 24);

    $this->put('/settings/recall', [
        'api_key' => $apiKey,
        'webhook_secret' => $webhookSecret,
    ])->assertRedirect('/settings/recall');

    expect(SecureSetting::query()->where('key', 'recall_api_key')->value('value'))->toBe($apiKey)
        ->and(SecureSetting::query()->where('key', 'recall_webhook_secret')->value('value'))->toBe($webhookSecret)
        ->and(DB::table('secure_settings')->where('key', 'recall_api_key')->value('value'))->not->toContain($apiKey)
        ->and(DB::table('secure_settings')->where('key', 'recall_webhook_secret')->value('value'))->not->toContain($webhookSecret);
});

it('preserves an existing secret when its submitted value is blank', function () {
    SecureSetting::create(['key' => 'recall_api_key', 'value' => 'recall-existing-api-key']);
    SecureSetting::create(['key' => 'recall_webhook_secret', 'value' => 'whsec_existing_webhook_secret']);

    $this->put('/settings/recall', [
        'api_key' => 'recall-'.str_repeat('c', 24),
        'webhook_secret' => '',
    ])->assertRedirect('/settings/recall');

    expect(SecureSetting::query()->where('key', 'recall_api_key')->value('value'))->toBe('recall-'.str_repeat('c', 24))
        ->and(SecureSetting::query()->where('key', 'recall_webhook_secret')->value('value'))->toBe('whsec_existing_webhook_secret');
});

it('rejects an invalid webhook secret', function () {
    $this->put('/settings/recall', [
        'api_key' => 'recall-'.str_repeat('d', 24),
        'webhook_secret' => 'invalid-webhook-secret',
    ])->assertSessionHasErrors(['webhook_secret']);

    expect(SecureSetting::query()->whereIn('key', ['recall_api_key', 'recall_webhook_secret'])->exists())->toBeFalse();
});

it('does not flash Recall credentials when validation fails', function () {
    $this->from('/settings/recall')->put('/settings/recall', [
        'api_key' => 'recall-'.str_repeat('e', 24),
        'webhook_secret' => 'invalid-webhook-secret',
    ])->assertSessionHasErrors(['webhook_secret']);

    expect(session()->get('_old_input', []))
        ->not->toHaveKey('api_key')
        ->not->toHaveKey('webhook_secret');
});
