<?php

use App\Enums\MeetingCaptureStatus;
use App\Enums\MeetingProvider;
use App\Models\ConversationSession;
use App\Models\MeetingCaptureSession;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Config::set('services.recall.api_key', 'recall-api-key-for-tests');
    Config::set('services.recall.webhook_secret', 'whsec_for_tests');
    Config::set('services.recall.region', 'us-west-2');
    Config::set('services.recall.base_url', 'https://us-west-2.recall.test');
    Config::set('services.recall.webhook_url', 'https://recall-tunnel.example.test/api/recall/webhooks');
    Config::set('services.recall.tunnel_host', 'recall-tunnel.example.test');

    Http::fake([
        'https://us-west-2.recall.test/api/v1/bot/*' => Http::response([
            'id' => 'bot-secret-id',
            'status' => 'ready',
        ], 201),
    ]);
});

it('validates before creating a conversation or bot', function () {
    $this->postJson('/meeting-captures', [
        'provider' => 'recall',
        'meeting_url' => 'https://teams.microsoft.com.example.test/l/meetup-join/secret',
        'idempotency_key' => (string) str()->uuid(),
    ])->assertUnprocessable();

    expect(ConversationSession::query()->count())->toBe(0)
        ->and(MeetingCaptureSession::query()->count())->toBe(0);
    Http::assertNothingSent();

    Config::set('services.recall.api_key');

    $this->postJson('/meeting-captures', [
        'provider' => 'recall',
        'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/valid',
        'idempotency_key' => (string) str()->uuid(),
    ])->assertStatus(503)
        ->assertJsonPath('error.code', 'recall_not_configured');

    expect(ConversationSession::query()->count())->toBe(0)
        ->and(MeetingCaptureSession::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('returns a secret-free Recall readiness status', function () {
    $response = $this->getJson('/api/recall/status')
        ->assertOk()
        ->assertExactJson([
            'configured' => true,
            'region' => 'us-west-2',
            'webhook_url' => 'https://recall-tunnel.example.test/api/recall/webhooks',
            'webhook_ready' => true,
        ]);

    expect($response->getContent())
        ->not->toContain('recall-api-key-for-tests')
        ->not->toContain('whsec_for_tests');

    Config::set('services.recall.tunnel_host', 'other.example.test');

    $this->getJson('/api/recall/status')
        ->assertOk()
        ->assertJsonPath('webhook_ready', false);
});

it('starts Recall and local captures with safe responses', function () {
    $recall = $this->postJson('/meeting-captures', [
        'provider' => 'recall',
        'meeting_url' => 'HTTPS://TEAMS.MICROSOFT.COM/l/meetup-join/secret?context=credential#invite',
        'idempotency_key' => (string) str()->uuid(),
        'template_used' => 'discovery',
        'customer_name' => 'Ada',
        'customer_company' => 'Analytical Engines',
    ])->assertCreated()
        ->assertJsonPath('capture.provider', 'recall')
        ->assertJsonPath('capture.status', 'creating')
        ->assertJsonStructure([
            'capture' => ['id', 'conversation_id', 'provider', 'status', 'failure_code', 'failure_message'],
            'links' => ['events', 'stop', 'claim_analysis'],
        ]);

    expect($recall->getContent())
        ->not->toContain('secret?context=credential')
        ->not->toContain('bot-secret-id')
        ->not->toContain('meeting_url_hash');

    $local = $this->postJson('/meeting-captures', [
        'provider' => 'local',
        'idempotency_key' => (string) str()->uuid(),
    ])->assertCreated()
        ->assertJsonPath('capture.provider', 'local')
        ->assertJsonPath('capture.status', 'active');

    expect(ConversationSession::query()->findOrFail(
        $recall->json('capture.conversation_id'),
    )->template_used)->toBe('discovery')
        ->and($local->json('capture.conversation_id'))->toBeInt();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data()['meeting_url']
            === 'https://teams.microsoft.com/l/meetup-join/secret?context=credential');
});

it('replays start idempotently', function () {
    $idempotencyKey = (string) str()->uuid();
    $payload = [
        'provider' => 'recall',
        'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/replay',
        'idempotency_key' => $idempotencyKey,
    ];

    $first = $this->postJson('/meeting-captures', $payload)->assertCreated();
    $second = $this->postJson('/meeting-captures', $payload)->assertOk();

    expect($second->json('capture.id'))->toBe($first->json('capture.id'))
        ->and($second->json('capture.conversation_id'))->toBe($first->json('capture.conversation_id'))
        ->and(ConversationSession::query()->count())->toBe(1)
        ->and(MeetingCaptureSession::query()->count())->toBe(1);

    Http::assertSentCount(2);
});

it('stops idempotently', function () {
    $conversation = ConversationSession::factory()->ongoing()->create();
    $capture = MeetingCaptureSession::query()->create([
        'conversation_session_id' => $conversation->id,
        'provider' => MeetingProvider::Recall,
        'provider_bot_id' => 'bot-to-stop',
        'platform' => 'teams',
        'status' => MeetingCaptureStatus::Active,
        'idempotency_key' => (string) str()->uuid(),
    ]);

    $this->postJson("/meeting-captures/{$capture->id}/stop")
        ->assertOk()
        ->assertJsonPath('capture.status', 'stopping');
    $this->postJson("/meeting-captures/{$capture->id}/stop")
        ->assertOk()
        ->assertJsonPath('capture.status', 'stopping');

    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/leave_call/')))
        ->toHaveCount(1);
});

it('sanitizes hostile persisted failures on replay and stop', function () {
    $conversation = ConversationSession::factory()->ongoing()->create();
    $meetingUrl = 'https://teams.microsoft.com/l/meetup-join/hostile-failure';
    $capture = MeetingCaptureSession::query()->create([
        'conversation_session_id' => $conversation->id,
        'provider' => MeetingProvider::Recall,
        'provider_bot_id' => 'bot-hostile',
        'platform' => 'teams',
        'status' => MeetingCaptureStatus::Failed,
        'meeting_url_hash' => hash('sha256', $meetingUrl),
        'idempotency_key' => (string) str()->uuid(),
        'failure_code' => 'provider/fatal;drop-secret',
        'failure_message' => 'Provider says meeting credential=top-secret and /Users/private/path.',
    ]);

    $replay = $this->postJson('/meeting-captures', [
        'provider' => 'recall',
        'meeting_url' => $meetingUrl,
        'idempotency_key' => $capture->idempotency_key,
    ])->assertOk()
        ->assertJsonPath('capture.failure_code', 'recall_bot_failed')
        ->assertJsonPath('capture.failure_message', 'Meeting capture failed.');

    $stop = $this->postJson("/meeting-captures/{$capture->id}/stop")
        ->assertOk()
        ->assertJsonPath('capture.failure_code', 'recall_bot_failed')
        ->assertJsonPath('capture.failure_message', 'Meeting capture failed.');

    foreach ([$replay->getContent(), $stop->getContent()] as $content) {
        expect($content)
            ->not->toContain('provider/fatal')
            ->not->toContain('top-secret')
            ->not->toContain('/Users/private/path');
    }
});

it('sanitizes renderer-facing Recall catch errors from real upstream failures', function () {
    Http::swap(new Factory);
    Http::fake([
        'https://us-west-2.recall.test/*' => Http::response([
            'detail' => 'Authorization: Bearer upstream-secret',
            'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/private-token',
        ], 500),
    ]);

    $upstreamFailure = $this->postJson('/meeting-captures', [
        'provider' => 'recall',
        'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/upstream-failure',
        'idempotency_key' => (string) str()->uuid(),
    ])->assertStatus(502)
        ->assertExactJson([
            'error' => [
                'code' => 'recall_bot_failed',
                'message' => 'Meeting capture failed.',
            ],
        ]);

    $conversation = ConversationSession::factory()->ongoing()->create();
    $idempotencyKey = (string) str()->uuid();
    MeetingCaptureSession::query()->create([
        'conversation_session_id' => $conversation->id,
        'provider' => MeetingProvider::Recall,
        'platform' => 'teams',
        'status' => MeetingCaptureStatus::Creating,
        'meeting_url_hash' => hash(
            'sha256',
            'https://teams.microsoft.com/l/meetup-join/original',
        ),
        'idempotency_key' => $idempotencyKey,
    ]);

    $conflict = $this->postJson('/meeting-captures', [
        'provider' => 'recall',
        'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/different',
        'idempotency_key' => $idempotencyKey,
    ])->assertStatus(409)
        ->assertExactJson([
            'error' => [
                'code' => 'recall_bot_failed',
                'message' => 'Meeting capture failed.',
            ],
        ]);

    foreach ([$upstreamFailure->getContent(), $conflict->getContent()] as $content) {
        expect($content)
            ->not->toContain('recall_http_500')
            ->not->toContain('recall_idempotency_conflict')
            ->not->toContain('upstream-secret')
            ->not->toContain('private-token')
            ->not->toContain('another meeting');
    }
});
