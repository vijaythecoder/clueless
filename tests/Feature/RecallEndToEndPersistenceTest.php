<?php

use App\Enums\AnalysisDeliveryStatus;
use App\Enums\MeetingCaptureStatus;
use App\Enums\MeetingProvider;
use App\Models\ConversationSession;
use App\Models\ConversationTranscript;
use App\Models\MeetingAnalysisDelivery;
use App\Models\MeetingCaptureSession;
use App\Models\MeetingParticipant;
use App\Models\ProviderEvent;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    $this->task11SigningKey = random_bytes(32);
    Config::set('openai.recall_analysis.driver', 'realtime');
    Config::set('services.recall.webhook_secret', 'whsec_'.base64_encode($this->task11SigningKey));
    Config::set('services.recall.webhook_tolerance_seconds', 300);
    Config::set('services.recall.tunnel_host', null);

    $this->task11Conversation = ConversationSession::factory()->ongoing()->create([
        'started_at' => now()->subMinute(),
    ]);
    $this->task11Capture = MeetingCaptureSession::query()->create([
        'conversation_session_id' => $this->task11Conversation->id,
        'provider' => MeetingProvider::Recall,
        'provider_bot_id' => 'bot_test_123',
        'platform' => 'microsoft_teams',
        'status' => MeetingCaptureStatus::Active,
        'meeting_url_hash' => hash('sha256', 'https://teams.microsoft.com/l/meetup-join/secret-token'),
        'idempotency_key' => (string) str()->uuid(),
        'started_at' => now()->subMinute(),
    ]);
});

function task11RecallFixture(string $fixture): array
{
    $contents = file_get_contents(base_path("tests/Fixtures/Recall/{$fixture}.json"));

    if ($contents === false) {
        throw new RuntimeException("Unable to load Recall fixture [{$fixture}].");
    }

    return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
}

function task11RecallHeaders(string $webhookId, string $body, string $signingKey): array
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

function task11PostRecallWebhook($test, array $payload, string $webhookId): mixed
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return $test->call(
        'POST',
        '/api/recall/webhooks',
        [],
        [],
        [],
        task11RecallHeaders($webhookId, $body, $test->task11SigningKey),
        $body,
    );
}

/**
 * @return array{
 *     salesperson: MeetingParticipant,
 *     customer: MeetingParticipant,
 *     transcripts: \Illuminate\Support\Collection<int, ConversationTranscript>
 * }
 */
function task11PersistNamedTurns($test): array
{
    $salespersonJoin = task11RecallFixture('participant-join');
    task11PostRecallWebhook($test, $salespersonJoin, 'task11-participant-salesperson')->assertNoContent();

    $customerJoin = task11RecallFixture('participant-join');
    data_set($customerJoin, 'data.participant_events.id', 'participant_events_customer');
    data_set($customerJoin, 'data.data.participant.id', 84);
    data_set($customerJoin, 'data.data.participant.name', 'Grace Hopper');
    data_set($customerJoin, 'data.data.participant.email', 'grace@example.test');
    task11PostRecallWebhook($test, $customerJoin, 'task11-participant-customer')->assertNoContent();

    $salespersonFinal = task11RecallFixture('transcript-final');
    task11PostRecallWebhook($test, $salespersonFinal, 'task11-final-salesperson')->assertNoContent();

    $customerFinal = task11RecallFixture('transcript-final');
    data_set($customerFinal, 'data.transcript.id', 'artifact_final_customer');
    data_set($customerFinal, 'data.data.participant.id', 84);
    data_set($customerFinal, 'data.data.participant.name', 'Grace Hopper');
    data_set($customerFinal, 'data.data.participant.email', 'grace@example.test');
    data_set($customerFinal, 'data.data.words', [
        [
            'text' => 'Manual reporting',
            'start_timestamp' => ['relative' => 4.2],
            'end_timestamp' => ['relative' => 4.8],
        ],
        [
            'text' => 'takes too long',
            'start_timestamp' => ['relative' => 4.81],
            'end_timestamp' => ['relative' => 5.6],
        ],
    ]);
    task11PostRecallWebhook($test, $customerFinal, 'task11-final-customer')->assertNoContent();

    $salesperson = MeetingParticipant::query()
        ->where('meeting_capture_session_id', $test->task11Capture->id)
        ->where('provider_participant_id', '42')
        ->sole();
    $customer = MeetingParticipant::query()
        ->where('meeting_capture_session_id', $test->task11Capture->id)
        ->where('provider_participant_id', '84')
        ->sole();

    $test->patchJson(
        "/meeting-captures/{$test->task11Capture->id}/participants/{$salesperson->id}",
        ['sales_role' => 'salesperson'],
    )->assertOk();

    return [
        'salesperson' => $salesperson->fresh(),
        'customer' => $customer->fresh(),
        'transcripts' => ConversationTranscript::query()
            ->where('meeting_capture_session_id', $test->task11Capture->id)
            ->orderBy('order_index')
            ->get(),
    ];
}

it('accepts named Recall participants with provider evidence metadata', function () {
    $state = task11PersistNamedTurns($this);

    expect($state['transcripts'])->toHaveCount(2)
        ->and($state['transcripts'][0]->participant->display_name)->toBe('Ada Lovelace')
        ->and($state['transcripts'][0]->speaker)->toBe('salesperson')
        ->and($state['transcripts'][0]->provider)->toBe(MeetingProvider::Recall)
        ->and($state['transcripts'][0]->provider_item_id)->toStartWith('recall_')
        ->and($state['transcripts'][1]->participant->display_name)->toBe('Grace Hopper')
        ->and($state['transcripts'][1]->speaker)->toBe('customer')
        ->and($state['transcripts'][1]->started_offset_ms)->toBe(4200)
        ->and($state['transcripts'][1]->ended_offset_ms)->toBe(5600);
});

it('ignores blank Recall final text while retaining a diagnostic event', function () {
    task11PostRecallWebhook(
        $this,
        task11RecallFixture('transcript-empty-final'),
        'task11-empty-final',
    )->assertNoContent();

    expect(ConversationTranscript::query()
        ->where('meeting_capture_session_id', $this->task11Capture->id)
        ->count())->toBe(0)
        ->and(ProviderEvent::query()
            ->where('provider_webhook_id', 'task11-empty-final')
            ->value('payload'))->toMatchArray([
                'diagnostic' => 'empty_final',
                'is_empty_final' => true,
            ]);
});

it('upserts duplicate provider item ids without a server error', function () {
    $payload = task11RecallFixture('transcript-final');

    task11PostRecallWebhook($this, $payload, 'task11-duplicate-webhook')->assertNoContent();
    task11PostRecallWebhook($this, $payload, 'task11-duplicate-webhook')->assertNoContent();
    task11PostRecallWebhook($this, $payload, 'task11-semantic-replay')->assertNoContent();

    expect(ProviderEvent::query()
        ->where('meeting_capture_session_id', $this->task11Capture->id)
        ->count())->toBe(2)
        ->and(ConversationTranscript::query()
            ->where('meeting_capture_session_id', $this->task11Capture->id)
            ->count())->toBe(1)
        ->and(MeetingAnalysisDelivery::query()
            ->where('meeting_capture_session_id', $this->task11Capture->id)
            ->count())->toBe(1);
});

it('orders Recall history by provider event cursor', function () {
    $state = task11PersistNamedTurns($this);
    $expectedCursors = $state['transcripts']->pluck('order_index')->all();

    $response = $this->get("/conversations/{$this->task11Conversation->id}")->assertOk();
    $transcripts = $response->original->getData()['page']['props']['transcripts']['data'];

    expect(array_column($transcripts, 'order_index'))->toBe($expectedCursors)
        ->and($expectedCursors[0])->toBeLessThan($expectedCursors[1]);
});

it('returns persisted named turns and evidence-backed Recall insights', function () {
    $state = task11PersistNamedTurns($this);
    $throughCursor = ProviderEvent::query()->max('id');
    $claim = $this->postJson(
        "/meeting-captures/{$this->task11Capture->id}/analysis-deliveries/claim",
        ['through_cursor' => $throughCursor],
    )->assertOk();
    $deliveries = $claim->json('deliveries');

    expect($deliveries)->toHaveCount(2);

    $firstItemId = $state['transcripts'][0]->provider_item_id;
    $secondItemId = $state['transcripts'][1]->provider_item_id;

    $this->postJson("/conversations/{$this->task11Conversation->id}/insight", [
        'insight_type' => 'pain_point',
        'tool_call_id' => 'task11-pain-call',
        'card_type' => 'pain_point',
        'analysis_delivery_id' => $deliveries[0]['id'],
        'evidence_item_ids' => [$firstItemId],
        'data' => [
            'text' => 'Manual reporting is slowing the team down.',
            'category' => 'Operations',
            'severity' => 'high',
        ],
        'captured_at' => now()->timestamp * 1000,
    ])->assertOk();

    $this->postJson("/conversations/{$this->task11Conversation->id}/insight", [
        'insight_type' => 'topic',
        'tool_call_id' => 'task11-topic-call',
        'card_type' => 'discussion_topic',
        'analysis_delivery_id' => $deliveries[1]['id'],
        'evidence_item_ids' => [$firstItemId, $secondItemId],
        'data' => [
            'name' => 'Reporting workflow',
            'sentiment' => 'negative',
            'context' => 'The customer described time lost to manual reporting.',
        ],
        'captured_at' => now()->timestamp * 1000,
    ])->assertOk();

    foreach ($deliveries as $delivery) {
        $this->postJson(
            "/meeting-captures/{$this->task11Capture->id}/analysis-deliveries/{$delivery['id']}/ack",
            [
                'lease_token' => $delivery['lease_token'],
                'status' => AnalysisDeliveryStatus::Completed->value,
            ],
        )->assertOk();
    }

    $response = $this->get("/conversations/{$this->task11Conversation->id}")->assertOk();
    $props = $response->original->getData()['page']['props'];

    expect($props['transcripts']['data'])->toHaveCount(2)
        ->and($props['transcripts']['data'][0])->toMatchArray([
            'participant_display_name' => 'Ada Lovelace',
            'sales_role' => 'salesperson',
            'is_you' => true,
            'provider' => 'recall',
            'provider_item_id' => $firstItemId,
            'status' => 'final',
        ])
        ->and($props['transcripts']['data'][1])->toMatchArray([
            'participant_display_name' => 'Grace Hopper',
            'sales_role' => 'customer',
            'is_you' => false,
            'provider' => 'recall',
            'provider_item_id' => $secondItemId,
            'status' => 'final',
        ])
        ->and($props['insights']['data'][0]['evidence_item_ids'])->toContain($secondItemId)
        ->and($props['insights']['data'][1]['evidence_item_ids'])->toBe([$firstItemId])
        ->and(MeetingAnalysisDelivery::query()
            ->where('status', AnalysisDeliveryStatus::Completed)
            ->count())->toBe(2);
});

it('never returns participant email bot id meeting url or secrets', function () {
    task11PersistNamedTurns($this);
    $this->task11Capture->forceFill([
        'failure_code' => 'provider/secret-token',
        'failure_message' => 'Meeting https://teams.microsoft.com/l/meetup-join/private failed for grace@example.test',
        'status' => MeetingCaptureStatus::Failed,
    ])->save();

    $response = $this->get("/conversations/{$this->task11Conversation->id}")->assertOk();
    $content = $response->getContent();

    expect($content)
        ->not->toContain('ada@example.test')
        ->not->toContain('grace@example.test')
        ->not->toContain('bot_test_123')
        ->not->toContain('meetup-join')
        ->not->toContain('secret-token')
        ->not->toContain($this->task11Capture->meeting_url_hash);
});

it('ends a capture and conversation idempotently', function () {
    $this->task11Capture->forceFill([
        'status' => MeetingCaptureStatus::Ended,
        'ended_at' => now(),
    ])->save();

    $firstCaptureEnd = $this->postJson("/meeting-captures/{$this->task11Capture->id}/stop")
        ->assertOk();
    $secondCaptureEnd = $this->postJson("/meeting-captures/{$this->task11Capture->id}/stop")
        ->assertOk();

    $this->postJson("/conversations/{$this->task11Conversation->id}/end", [
        'duration_seconds' => 300,
        'final_intent' => 'evaluation',
    ])->assertOk();
    $endedAt = $this->task11Conversation->fresh()->ended_at;
    $this->postJson("/conversations/{$this->task11Conversation->id}/end", [
        'duration_seconds' => 0,
    ])->assertOk();

    expect($firstCaptureEnd->json('capture.status'))->toBe('ended')
        ->and($secondCaptureEnd->json('capture.status'))->toBe('ended')
        ->and($this->task11Conversation->fresh()->ended_at?->equalTo($endedAt))->toBeTrue()
        ->and($this->task11Conversation->fresh()->duration_seconds)->toBe(300)
        ->and($this->task11Conversation->fresh()->final_intent)->toBe('evaluation');
});

it('maps upstream errors to stable renderer-safe codes', function () {
    $this->task11Capture->forceFill([
        'status' => MeetingCaptureStatus::Failed,
        'failure_code' => 'recall_http_500',
        'failure_message' => 'Authorization: Bearer secret https://teams.microsoft.com/private',
    ])->save();

    $response = $this->get("/conversations/{$this->task11Conversation->id}")->assertOk();
    $capture = $response->original->getData()['page']['props']['capture'];

    expect($capture)->toBe([
        'provider' => 'recall',
        'status' => 'failed',
        'failure_code' => 'recall_bot_failed',
        'failure_message' => 'Meeting capture failed.',
    ])
        ->and(json_encode($capture, JSON_THROW_ON_ERROR))
        ->not->toContain('secret')
        ->not->toContain('teams.microsoft.com')
        ->not->toContain('recall_http_500');
});
