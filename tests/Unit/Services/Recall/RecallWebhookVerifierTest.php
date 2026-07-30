<?php

use App\Exceptions\InvalidRecallWebhook;
use App\Services\Recall\RecallWebhookVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->signingKey = random_bytes(32);
    $this->webhookSecret = 'whsec_'.base64_encode($this->signingKey);

    Config::set('services.recall.webhook_secret', $this->webhookSecret);
    Config::set('services.recall.webhook_tolerance_seconds', 300);

    $this->verifier = app(RecallWebhookVerifier::class);
});

function recallWebhookSignature(string $webhookId, string $timestamp, string $rawBody, string $key): string
{
    return 'v1,'.base64_encode(hash_hmac('sha256', implode('.', [$webhookId, $timestamp, $rawBody]), $key, true));
}

it('accepts a valid recall signature', function () {
    $webhookId = 'wh_123';
    $timestamp = (string) time();
    $rawBody = '{"event":"bot.status_change"}';

    $this->verifier->verify(
        $rawBody,
        $webhookId,
        $timestamp,
        recallWebhookSignature($webhookId, $timestamp, $rawBody, $this->signingKey),
    );

    expect(true)->toBeTrue();
});

it('accepts one valid v1 signature during rotation', function () {
    $webhookId = 'wh_123';
    $timestamp = (string) time();
    $rawBody = '{"event":"bot.status_change"}';
    $signature = implode(' ', [
        'v2,'.base64_encode(random_bytes(32)),
        'v1,'.base64_encode(random_bytes(32)),
        recallWebhookSignature($webhookId, $timestamp, $rawBody, $this->signingKey),
    ]);

    $this->verifier->verify($rawBody, $webhookId, $timestamp, $signature);

    expect(true)->toBeTrue();
});

it('rejects an altered raw body', function () {
    $webhookId = 'wh_123';
    $timestamp = (string) time();
    $rawBody = '{"event":"bot.status_change"}';

    expect(fn () => $this->verifier->verify(
        '{"event":"bot.status_changed"}',
        $webhookId,
        $timestamp,
        recallWebhookSignature($webhookId, $timestamp, $rawBody, $this->signingKey),
    ))->toThrow(InvalidRecallWebhook::class);
});

it('rejects a stale and a future timestamp', function () {
    $webhookId = 'wh_123';
    $rawBody = '{"event":"bot.status_change"}';

    foreach ([(string) (time() - 301), (string) (time() + 301)] as $timestamp) {
        expect(fn () => $this->verifier->verify(
            $rawBody,
            $webhookId,
            $timestamp,
            recallWebhookSignature($webhookId, $timestamp, $rawBody, $this->signingKey),
        ))->toThrow(InvalidRecallWebhook::class);
    }
});

it('rejects missing headers and malformed base64', function () {
    $rawBody = '{"event":"bot.status_change"}';

    expect(fn () => $this->verifier->verify($rawBody, '', '', ''))
        ->toThrow(InvalidRecallWebhook::class)
        ->and(fn () => $this->verifier->verify($rawBody, 'wh_123', (string) time(), 'v1,not-base64!'))
        ->toThrow(InvalidRecallWebhook::class);
});

it('rejects a secret without whsec prefix', function () {
    Config::set('services.recall.webhook_secret', base64_encode($this->signingKey));

    expect(fn () => $this->verifier->verify(
        '{}',
        'wh_123',
        (string) time(),
        'v1,'.base64_encode(random_bytes(32)),
    ))->toThrow(InvalidRecallWebhook::class);
});

it('rejects an unequal length signature safely', function () {
    expect(fn () => $this->verifier->verify(
        '{}',
        'wh_123',
        (string) time(),
        'v1,'.base64_encode(random_bytes(31)),
    ))->toThrow(InvalidRecallWebhook::class);
});
