<?php

use App\Http\Middleware\LimitRecallWebhookBody;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Config::set('services.recall.ingress_only', false);
    Config::set('services.recall.tunnel_host', 'recall-tunnel.test');
    Config::set('services.recall.webhook_max_bytes', 1048576);

    Route::any('/api/recall/webhooks', fn () => response('webhook route'));
    Route::any('/recall-webhook-boundary-test', fn () => response('local route'));
});

it('allows only post recall webhook on the configured tunnel host', function () {
    $this->post('http://recall-tunnel.test:443/api/recall/webhooks')
        ->assertOk();

    $this->get('http://recall-tunnel.test/api/recall/webhooks')
        ->assertNotFound();

    $this->post('http://recall-tunnel.test/recall-webhook-boundary-test')
        ->assertNotFound();
});

it('allows only post recall webhook in ingress only mode even with a spoofed host', function () {
    Config::set('services.recall.ingress_only', true);

    $this->post('http://spoofed-host.test/api/recall/webhooks')
        ->assertOk();

    $this->get('http://spoofed-host.test/api/recall/webhooks')
        ->assertNotFound();

    $this->post('http://spoofed-host.test/recall-webhook-boundary-test')
        ->assertNotFound();
});

it('honors the process ingress only marker when cached config is false and host is spoofed', function () {
    $previousIngressOnly = getenv('RECALL_INGRESS_ONLY');

    try {
        Config::set('services.recall.ingress_only', false);
        putenv('RECALL_INGRESS_ONLY=true');

        $this->post('http://spoofed-host.test/api/recall/webhooks')
            ->assertOk();

        $this->get('http://spoofed-host.test/api/recall/webhooks')
            ->assertNotFound();

        $this->post('http://spoofed-host.test/recall-webhook-boundary-test')
            ->assertNotFound();
    } finally {
        $previousIngressOnly === false
            ? putenv('RECALL_INGRESS_ONLY')
            : putenv('RECALL_INGRESS_ONLY='.$previousIngressOnly);
    }
});

it('ignores x forwarded host', function () {
    $this->withHeader('X-Forwarded-Host', 'recall-tunnel.test')
        ->get('http://clueless.test/recall-webhook-boundary-test')
        ->assertOk();
});

it('preserves local routes', function () {
    $this->get('http://localhost/recall-webhook-boundary-test')
        ->assertOk();
});

it('rejects content length above one mebibyte', function () {
    $request = Request::create('/api/recall/webhooks', 'POST', [], [], [], [
        'CONTENT_LENGTH' => '1048577',
    ]);

    $response = app(LimitRecallWebhookBody::class)->handle($request, fn () => response('accepted'));

    expect($response->getStatusCode())->toBe(413);
});

it('rejects an oversized raw body without content length', function () {
    $request = Request::create('/api/recall/webhooks', 'POST', [], [], [], [], str_repeat('a', 1048577));

    $response = app(LimitRecallWebhookBody::class)->handle($request, fn () => response('accepted'));

    expect($response->getStatusCode())->toBe(413);
});
