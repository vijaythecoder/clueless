<?php

use App\Enums\MeetingCaptureStatus;
use App\Enums\MeetingProvider;
use App\Models\ConversationSession;
use App\Models\ConversationTranscript;
use App\Models\MeetingAnalysisDelivery;
use App\Models\MeetingCaptureSession;
use App\Models\MeetingParticipant;
use App\Models\ProviderEvent;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->signingKey = random_bytes(32);
    Config::set('services.recall.webhook_secret', 'whsec_'.base64_encode($this->signingKey));
    Config::set('services.recall.webhook_tolerance_seconds', 300);
    Config::set('services.recall.tunnel_host', null);

    $this->conversation = ConversationSession::factory()->ongoing()->create([
        'started_at' => now()->subMinute(),
    ]);
    $this->capture = MeetingCaptureSession::query()->create([
        'conversation_session_id' => $this->conversation->id,
        'provider' => MeetingProvider::Recall,
        'provider_bot_id' => 'bot_test_123',
        'platform' => 'microsoft_teams',
        'status' => MeetingCaptureStatus::Joining,
        'idempotency_key' => (string) str()->uuid(),
        'started_at' => now()->subMinute(),
    ]);
});

function recallWebhookPayload(string $fixture): string
{
    $contents = file_get_contents(base_path("tests/Fixtures/Recall/{$fixture}.json"));

    if ($contents === false) {
        throw new RuntimeException("Unable to load Recall fixture [{$fixture}].");
    }

    return $contents;
}

function recallWebhookHeaders(string $webhookId, string $body, string $signingKey): array
{
    $timestamp = (string) time();
    $signature = base64_encode(hash_hmac(
        'sha256',
        implode('.', [$webhookId, $timestamp, $body]),
        $signingKey,
        true,
    ));

    return [
        'HTTP_WEBHOOK_ID' => $webhookId,
        'HTTP_WEBHOOK_TIMESTAMP' => $timestamp,
        'HTTP_WEBHOOK_SIGNATURE' => "v1,{$signature}",
        'CONTENT_TYPE' => 'application/json',
    ];
}

function postRecallWebhook($test, string $fixture, string $webhookId): mixed
{
    $body = recallWebhookPayload($fixture);

    return $test->call(
        'POST',
        '/api/recall/webhooks',
        [],
        [],
        [],
        recallWebhookHeaders($webhookId, $body, $test->signingKey),
        $body,
    );
}

it('verifies the untouched body before decoding', function () {
    $body = '{"event":"transcript.data","data":';
    $this->call('POST', '/api/recall/webhooks', [], [], [], [
        'HTTP_WEBHOOK_ID' => 'wh_invalid_json_signature',
        'HTTP_WEBHOOK_TIMESTAMP' => (string) time(),
        'HTTP_WEBHOOK_SIGNATURE' => 'v1,invalid',
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertUnauthorized();

    $headers = recallWebhookHeaders('wh_invalid_json', $body, $this->signingKey);

    $this->call('POST', '/api/recall/webhooks', [], [], [], $headers, $body)
        ->assertStatus(400);

    expect(ProviderEvent::count())->toBe(0);
});

it('persists valid events and returns 204', function () {
    postRecallWebhook($this, 'transcript-final', 'wh_final_1')->assertNoContent();

    expect(ProviderEvent::count())->toBe(1)
        ->and(ConversationTranscript::count())->toBe(1)
        ->and(MeetingAnalysisDelivery::count())->toBe(1);
});

it('accepts legacy Svix signature headers', function () {
    $body = recallWebhookPayload('transcript-final');
    $headers = recallWebhookHeaders('wh_svix_final', $body, $this->signingKey);

    $this->call('POST', '/api/recall/webhooks', [], [], [], [
        'HTTP_SVIX_ID' => $headers['HTTP_WEBHOOK_ID'],
        'HTTP_SVIX_TIMESTAMP' => $headers['HTTP_WEBHOOK_TIMESTAMP'],
        'HTTP_SVIX_SIGNATURE' => $headers['HTTP_WEBHOOK_SIGNATURE'],
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertNoContent();

    expect(ProviderEvent::count())->toBe(1);
});

it('deduplicates webhook ids and semantic final retries', function () {
    postRecallWebhook($this, 'transcript-final', 'wh_final_1')->assertNoContent();
    postRecallWebhook($this, 'transcript-final', 'wh_final_1')->assertNoContent();
    postRecallWebhook($this, 'transcript-final', 'wh_final_2')->assertNoContent();

    expect(ProviderEvent::count())->toBe(2)
        ->and(ConversationTranscript::count())->toBe(1)
        ->and(MeetingAnalysisDelivery::count())->toBe(1);
});

it('uses the unique webhook winner and returns before applying a colliding payload', function () {
    postRecallWebhook($this, 'transcript-final', 'wh_collision')->assertNoContent();
    $createAttempts = 0;

    Event::listen(
        'eloquent.creating: '.ProviderEvent::class,
        function () use (&$createAttempts): void {
            $createAttempts++;
        },
    );

    postRecallWebhook($this, 'participant-update', 'wh_collision')->assertNoContent();

    expect($createAttempts)->toBe(1)
        ->and(ProviderEvent::count())->toBe(1)
        ->and(MeetingParticipant::query()->sole()->display_name)->toBe('Ada Lovelace');
});

it('upserts participant identity', function () {
    postRecallWebhook($this, 'participant-join', 'wh_participant_join')->assertNoContent();
    postRecallWebhook($this, 'participant-update', 'wh_participant_update')->assertNoContent();

    $participant = MeetingParticipant::query()->sole();

    expect($participant->display_name)->toBe('Ada Byron Lovelace')
        ->and($participant->email)->toBe('ada@example.test')
        ->and($participant->email_hash)->toBe(hash('sha256', 'ada@example.test'))
        ->and($participant->is_host)->toBeFalse();
});

it('catches the unique participant winner before updating identity', function () {
    postRecallWebhook($this, 'participant-join', 'wh_participant_join')->assertNoContent();
    $createAttempts = 0;

    Event::listen(
        'eloquent.creating: '.MeetingParticipant::class,
        function () use (&$createAttempts): void {
            $createAttempts++;
        },
    );

    postRecallWebhook($this, 'participant-update', 'wh_participant_update')->assertNoContent();

    expect($createAttempts)->toBe(1)
        ->and(MeetingParticipant::count())->toBe(1)
        ->and(MeetingParticipant::query()->sole()->display_name)->toBe('Ada Byron Lovelace');
});

it('persists partials only in provider events', function () {
    postRecallWebhook($this, 'transcript-partial', 'wh_partial_1')->assertNoContent();

    expect(ProviderEvent::count())->toBe(1)
        ->and(ProviderEvent::query()->sole()->provider_utterance_key)->toBe('42:1200')
        ->and(ConversationTranscript::count())->toBe(0)
        ->and(MeetingAnalysisDelivery::count())->toBe(0);
});

it('ignores late partial UI state after final', function () {
    postRecallWebhook($this, 'transcript-final', 'wh_final_1')->assertNoContent();
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    postRecallWebhook($this, 'transcript-out-of-order-partial', 'wh_partial_late')->assertNoContent();

    $final = ProviderEvent::query()->where('provider_webhook_id', 'wh_final_1')->sole();
    $latePartial = ProviderEvent::query()->where('provider_webhook_id', 'wh_partial_late')->sole();
    $usedIndexedLookup = collect($queries)->contains(
        fn (string $query): bool => str_contains($query, 'provider_utterance_key')
            && str_contains($query, 'provider_event_type')
            && str_contains($query, 'exists'),
    );

    expect($final->provider_utterance_key)->toBe('42:1200')
        ->and($latePartial->provider_utterance_key)->toBe('42:1200')
        ->and($latePartial->payload)->toMatchArray(['is_ignored' => true])
        ->and($usedIndexedLookup)->toBeTrue()
        ->and(ConversationTranscript::count())->toBe(1);
});

it('ignores empty finals with 204', function () {
    postRecallWebhook($this, 'transcript-empty-final', 'wh_empty_final')->assertNoContent();

    expect(ProviderEvent::count())->toBe(1)
        ->and(ProviderEvent::query()->sole()->payload)->toMatchArray(['diagnostic' => 'empty_final'])
        ->and(ConversationTranscript::count())->toBe(0)
        ->and(MeetingAnalysisDelivery::count())->toBe(0);
});

it('creates one analysis delivery per final', function () {
    postRecallWebhook($this, 'transcript-final', 'wh_final_1')->assertNoContent();
    postRecallWebhook($this, 'transcript-final', 'wh_final_2')->assertNoContent();

    expect(MeetingAnalysisDelivery::count())->toBe(1);
});

it('maps lifecycle states', function () {
    postRecallWebhook($this, 'bot-status', 'wh_status_current')->assertNoContent();

    expect($this->capture->refresh()->status)->toBe(MeetingCaptureStatus::Active);

    postRecallWebhook($this, 'legacy-bot-status', 'wh_status_legacy')->assertNoContent();

    expect($this->capture->refresh()->status)->toBe(MeetingCaptureStatus::WaitingRoom);
});

it('finalizes the linked conversation when Recall ends cleanly', function () {
    $this->capture->forceFill([
        'status' => MeetingCaptureStatus::Active,
        'started_at' => '2026-07-27 12:00:02',
    ])->save();
    $this->conversation->transcripts()->create([
        'speaker' => 'customer',
        'text' => 'We need the rollout completed this quarter.',
        'spoken_at' => '2026-07-27 12:01:00',
        'order_index' => 1,
    ]);
    $this->conversation->insights()->create([
        'insight_type' => 'key_insight',
        'data' => ['text' => 'The customer has a quarterly deadline.'],
        'captured_at' => '2026-07-27 12:01:01',
    ]);

    postRecallWebhook($this, 'bot-call-ended', 'wh_call_ended')->assertNoContent();

    $capture = $this->capture->fresh();
    $conversation = $this->conversation->fresh();

    expect($capture->status)->toBe(MeetingCaptureStatus::Ended)
        ->and($capture->ended_at?->toISOString())->toBe('2026-07-27T12:05:02.000000Z')
        ->and($conversation->ended_at?->equalTo($capture->ended_at))->toBeTrue()
        ->and($conversation->duration_seconds)->toBe(300)
        ->and($conversation->total_transcripts)->toBe(1)
        ->and($conversation->total_insights)->toBe(1)
        ->and($conversation->ai_summary)->toBeNull();
});

it('keeps terminal webhook replay idempotent', function () {
    $this->capture->forceFill([
        'status' => MeetingCaptureStatus::Active,
        'started_at' => '2026-07-27 12:00:02',
    ])->save();

    postRecallWebhook($this, 'bot-call-ended', 'wh_call_ended_first')->assertNoContent();
    $firstEndedAt = $this->conversation->fresh()->ended_at;

    $this->conversation->transcripts()->create([
        'speaker' => 'customer',
        'text' => 'This finalized turn arrived just before the replay.',
        'spoken_at' => '2026-07-27 12:05:01',
        'order_index' => 1,
    ]);

    postRecallWebhook($this, 'bot-call-ended', 'wh_call_ended_replay')->assertNoContent();

    $capture = $this->capture->fresh();
    $conversation = $this->conversation->fresh();

    expect($capture->status)->toBe(MeetingCaptureStatus::Ended)
        ->and($capture->ended_at?->equalTo($firstEndedAt))->toBeTrue()
        ->and($conversation->ended_at?->equalTo($firstEndedAt))->toBeTrue()
        ->and($conversation->duration_seconds)->toBe(300)
        ->and($conversation->total_transcripts)->toBe(1)
        ->and(ProviderEvent::query()
            ->where('provider_event_type', 'bot.call_ended')
            ->count())->toBe(2);
});

it('refreshes finalized conversation metrics for late transcript and insight delivery', function () {
    $this->capture->forceFill([
        'status' => MeetingCaptureStatus::Active,
        'started_at' => '2026-07-27 12:00:02',
    ])->save();

    postRecallWebhook($this, 'bot-call-ended', 'wh_call_ended_before_late_data')->assertNoContent();
    postRecallWebhook($this, 'transcript-final', 'wh_final_after_call_ended')->assertNoContent();

    $this->postJson("/conversations/{$this->conversation->id}/insight", [
        'insight_type' => 'talk_track',
        'tool_call_id' => 'late-talk-track',
        'card_type' => 'talk_track',
        'data' => [
            'text' => 'Confirm the implementation deadline before ending the call.',
            'priority' => 'high',
        ],
        'captured_at' => now()->timestamp * 1000,
    ])->assertOk();

    $conversation = $this->conversation->fresh();

    expect($conversation->ended_at)->not->toBeNull()
        ->and($conversation->total_transcripts)->toBe(1)
        ->and($conversation->total_insights)->toBe(1);
});

it('preserves server finalization when the renderer replays end', function () {
    $this->capture->forceFill([
        'status' => MeetingCaptureStatus::Active,
        'started_at' => '2026-07-27 12:00:02',
    ])->save();

    postRecallWebhook($this, 'bot-call-ended', 'wh_call_ended_before_renderer')->assertNoContent();
    $serverEndedAt = $this->conversation->fresh()->ended_at;

    $this->travel(5)->minutes();
    $this->postJson("/conversations/{$this->conversation->id}/end", [
        'duration_seconds' => 0,
        'final_intent' => 'evaluation',
    ])->assertOk();

    $conversation = $this->conversation->fresh();

    expect($conversation->ended_at?->equalTo($serverEndedAt))->toBeTrue()
        ->and($conversation->duration_seconds)->toBe(300)
        ->and($conversation->final_intent)->toBe('evaluation')
        ->and($conversation->ai_summary)->toBeNull();
});

it('does not turn a failed capture into a successful conversation', function () {
    $this->capture->forceFill([
        'status' => MeetingCaptureStatus::Failed,
        'failure_code' => 'recording_permission_denied',
        'failure_message' => 'Recording permission was denied.',
    ])->save();

    postRecallWebhook($this, 'bot-call-ended', 'wh_call_ended_after_failure')->assertNoContent();

    expect($this->capture->fresh()->status)->toBe(MeetingCaptureStatus::Failed)
        ->and($this->conversation->fresh()->ended_at)->toBeNull()
        ->and($this->conversation->fresh()->duration_seconds)->toBe(0);
});

it('accepts valid events after a capture has ended', function () {
    $this->capture->update(['status' => MeetingCaptureStatus::Ended]);

    postRecallWebhook($this, 'transcript-final', 'wh_final_after_end')->assertNoContent();

    expect(ConversationTranscript::count())->toBe(1);
});

it('writes nothing for invalid signatures', function () {
    $body = recallWebhookPayload('transcript-final');

    $this->call('POST', '/api/recall/webhooks', [], [], [], [
        'HTTP_WEBHOOK_ID' => 'wh_invalid_signature',
        'HTTP_WEBHOOK_TIMESTAMP' => (string) time(),
        'HTTP_WEBHOOK_SIGNATURE' => 'v1,invalid',
        'CONTENT_TYPE' => 'application/json',
    ], $body)
        ->assertUnauthorized()
        ->assertContent('');

    expect(ProviderEvent::count())->toBe(0)
        ->and(ConversationTranscript::count())->toBe(0);
});

it('returns a sanitized retryable response when credential lookup throws a query exception', function () {
    Config::set('app.debug', true);
    Log::spy();

    DB::connection()->beforeExecuting(
        function (string $query, array $bindings, Connection $connection): never {
            throw new QueryException(
                $connection->getName(),
                'select secret from /private/credential/path where value = "whsec_should_not_leak"',
                [],
                new RuntimeException('credential lookup exploded with sensitive SQL'),
            );
        },
    );

    $body = json_encode([
        'event' => 'transcript.data',
        'data' => [
            'bot' => ['id' => 'bot_test_123'],
            'extra_data' => ['private_marker' => 'raw_request_body_should_not_leak'],
        ],
    ], JSON_THROW_ON_ERROR);

    $response = $this->call(
        'POST',
        '/api/recall/webhooks',
        [],
        [],
        [],
        recallWebhookHeaders('wh_credential_query_failure', $body, $this->signingKey),
        $body,
    );

    $response
        ->assertStatus(500)
        ->assertContent('');

    expect($response->getContent())
        ->not->toContain('credential lookup exploded')
        ->not->toContain('/private/credential/path')
        ->not->toContain('select secret')
        ->not->toContain('whsec_should_not_leak')
        ->not->toContain('raw_request_body_should_not_leak');

    Log::shouldHaveReceived('error')->once()->with(
        'Unexpected Recall webhook failure',
        [
            'exception' => QueryException::class,
            'webhook_id' => 'wh_credential_query_failure',
        ],
    );
});

it('returns 404 for unknown bot', function () {
    $body = recallWebhookPayload('transcript-final');
    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    $payload['data']['bot']['id'] = 'bot_unknown';
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    $this->call(
        'POST',
        '/api/recall/webhooks',
        [],
        [],
        [],
        recallWebhookHeaders('wh_unknown_bot', $body, $this->signingKey),
        $body,
    )->assertNotFound();

    expect(ProviderEvent::count())->toBe(0);
});

it('returns 404 without matching a null-bot capture for missing or blank bot ids', function () {
    $this->capture->update(['provider_bot_id' => null]);

    foreach (['missing', 'blank'] as $case) {
        $payload = json_decode(recallWebhookPayload('transcript-final'), true, 512, JSON_THROW_ON_ERROR);

        if ($case === 'missing') {
            unset($payload['data']['bot']['id']);
        } else {
            $payload['data']['bot']['id'] = '   ';
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/recall/webhooks',
            [],
            [],
            [],
            recallWebhookHeaders("wh_{$case}_bot", $body, $this->signingKey),
            $body,
        )->assertNotFound();
    }

    expect(ProviderEvent::count())->toBe(0)
        ->and(MeetingParticipant::count())->toBe(0)
        ->and(ConversationTranscript::count())->toBe(0)
        ->and(MeetingAnalysisDelivery::count())->toBe(0);
});

it('returns a blank retryable response and sanitized log context on unexpected failure', function () {
    Config::set('app.debug', true);
    Log::spy();
    ProviderEvent::creating(fn () => throw new RuntimeException(
        'database failure at /private/secret/path with sensitive detail',
    ));

    $response = postRecallWebhook($this, 'transcript-final', 'wh_database_failure');

    $response
        ->assertStatus(500)
        ->assertContent('');

    expect($response->getContent())
        ->not->toContain('database failure')
        ->not->toContain('/private/secret/path')
        ->not->toContain('sensitive detail');

    Log::shouldHaveReceived('error')->once()->with(
        'Unexpected Recall webhook failure',
        [
            'exception' => RuntimeException::class,
            'webhook_id' => 'wh_database_failure',
        ],
    );
});

it('returns the same sanitized retryable response for unexpected normalization failures', function () {
    Config::set('app.debug', true);
    Log::shouldReceive('info')
        ->once()
        ->andThrow(new RuntimeException('normalizer failure at /private/normalizer/path'));
    Log::shouldReceive('error')->zeroOrMoreTimes();
    $body = json_encode([
        'event' => 'recording.created',
        'data' => ['bot' => ['id' => 'bot_test_123']],
    ], JSON_THROW_ON_ERROR);

    $response = $this->call(
        'POST',
        '/api/recall/webhooks',
        [],
        [],
        [],
        recallWebhookHeaders('wh_normalizer_failure', $body, $this->signingKey),
        $body,
    );

    $response
        ->assertStatus(500)
        ->assertContent('');

    expect($response->getContent())
        ->not->toContain('normalizer failure')
        ->not->toContain('/private/normalizer/path');
});
