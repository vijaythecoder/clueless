<?php

use App\Enums\MeetingCaptureStatus;
use App\Enums\MeetingProvider;
use App\Enums\SalesRole;
use App\Models\ConversationSession;
use App\Models\ConversationTranscript;
use App\Models\MeetingCaptureSession;
use App\Models\MeetingParticipant;
use App\Models\ProviderEvent;

beforeEach(function () {
    $conversation = ConversationSession::factory()->ongoing()->create();
    $this->capture = MeetingCaptureSession::query()->create([
        'conversation_session_id' => $conversation->id,
        'provider' => MeetingProvider::Recall,
        'provider_bot_id' => 'bot-private',
        'platform' => 'teams',
        'status' => MeetingCaptureStatus::Active,
        'idempotency_key' => (string) str()->uuid(),
    ]);
    $this->participant = MeetingParticipant::query()->create([
        'meeting_capture_session_id' => $this->capture->id,
        'provider_participant_id' => 'participant-1',
        'display_name' => 'Ada',
        'email' => 'ada@example.test',
        'is_bot' => false,
        'sales_role' => SalesRole::Unknown,
    ]);
});

function createMeetingFeedEvent(
    MeetingCaptureSession $capture,
    string $webhookId,
    string $type,
    array $payload,
    ?MeetingParticipant $participant = null,
): ProviderEvent {
    return ProviderEvent::query()->create([
        'meeting_capture_session_id' => $capture->id,
        'provider' => MeetingProvider::Recall,
        'provider_webhook_id' => $webhookId,
        'provider_event_type' => $type,
        'meeting_participant_id' => $participant?->id,
        'payload' => $payload,
        'received_at' => now(),
    ]);
}

it('pages provider events by monotonic cursor without gaps', function () {
    $first = createMeetingFeedEvent(
        $this->capture,
        'feed-1',
        'participant.join',
        ['display_name' => 'stale-name'],
        $this->participant,
    );
    $second = createMeetingFeedEvent(
        $this->capture,
        'feed-2',
        'transcript.partial',
        [
            'utterance_key' => 'participant-1:100',
            'text' => 'Hello',
            'started_offset_ms' => 100,
            'ended_offset_ms' => 300,
        ],
        $this->participant,
    );
    $third = createMeetingFeedEvent(
        $this->capture,
        'feed-3',
        'transcript.final',
        [
            'provider_item_id' => 'recall-final-1',
            'utterance_key' => 'participant-1:100',
            'text' => 'Hello there',
            'started_offset_ms' => 100,
            'ended_offset_ms' => 500,
        ],
        $this->participant,
    );

    $page = $this->getJson("/meeting-captures/{$this->capture->id}/events?after=0&limit=2")
        ->assertOk()
        ->assertJsonCount(2, 'events')
        ->assertJsonPath('events.0.cursor', $first->id)
        ->assertJsonPath('events.1.cursor', $second->id)
        ->assertJsonPath('next_cursor', $second->id)
        ->assertJsonPath('has_more', true);

    $this->getJson(
        "/meeting-captures/{$this->capture->id}/events?after={$page->json('next_cursor')}&limit=2",
    )->assertOk()
        ->assertJsonCount(1, 'events')
        ->assertJsonPath('events.0.cursor', $third->id)
        ->assertJsonPath('next_cursor', $third->id)
        ->assertJsonPath('has_more', false);
});

it('clamps event limits and returns current capture state', function () {
    foreach (range(1, 205) as $index) {
        createMeetingFeedEvent(
            $this->capture,
            "feed-limit-{$index}",
            'bot.in_call_recording',
            ['capture_status' => 'active'],
        );
    }

    $this->capture->update([
        'status' => MeetingCaptureStatus::Failed,
        'failure_code' => 'recall_bot_failed',
        'failure_message' => 'The meeting ended unexpectedly.',
    ]);

    $this->getJson("/meeting-captures/{$this->capture->id}/events?after=0&limit=999")
        ->assertOk()
        ->assertJsonCount(200, 'events')
        ->assertJsonPath('has_more', true)
        ->assertJsonPath('capture.status', 'failed')
        ->assertJsonPath('capture.failure_code', 'recall_bot_failed');

    $this->getJson("/meeting-captures/{$this->capture->id}/events?after=-1")
        ->assertUnprocessable();
});

it('serializes current participant identity and role without private fields', function () {
    $event = createMeetingFeedEvent(
        $this->capture,
        'feed-current-role',
        'transcript.final',
        [
            'utterance_key' => 'participant-1:1000',
            'text' => 'Current role please',
            'started_offset_ms' => 1000,
            'ended_offset_ms' => 1400,
        ],
        $this->participant,
    );
    ConversationTranscript::query()->create([
        'session_id' => $this->capture->conversation_session_id,
        'speaker' => SalesRole::Unknown->value,
        'source_stream' => 'recall',
        'text' => 'Current role please',
        'spoken_at' => now(),
        'status' => 'final',
        'order_index' => $event->id,
        'meeting_capture_session_id' => $this->capture->id,
        'meeting_participant_id' => $this->participant->id,
        'provider' => MeetingProvider::Recall,
        'provider_item_id' => 'recall-final-current',
        'started_offset_ms' => 1000,
        'ended_offset_ms' => 1400,
    ]);
    $this->participant->update([
        'display_name' => 'Ada Lovelace',
        'sales_role' => SalesRole::Salesperson,
    ]);

    $response = $this->getJson("/meeting-captures/{$this->capture->id}/events")
        ->assertOk()
        ->assertJsonPath('events.0.cursor', $event->id)
        ->assertJsonPath('events.0.turn.display_name', 'Ada Lovelace')
        ->assertJsonPath('events.0.turn.sales_role', 'salesperson')
        ->assertJsonPath('events.0.turn.provider_item_id', 'recall-final-current');

    expect($response->getContent())
        ->not->toContain('ada@example.test')
        ->not->toContain('bot-private')
        ->not->toContain('provider_bot_id');
});

it('advances diagnostic and incomplete events as frontend-safe no-ops', function () {
    $emptyFinal = createMeetingFeedEvent(
        $this->capture,
        'feed-empty-final',
        'transcript.final',
        [
            'is_empty_final' => true,
            'utterance_key' => 'participant-1:2000',
            'text' => null,
        ],
        $this->participant,
    );
    $ignoredPartial = createMeetingFeedEvent(
        $this->capture,
        'feed-ignored-partial',
        'transcript.partial',
        [
            'is_ignored' => true,
            'utterance_key' => 'participant-1:2000',
            'text' => 'late text that must not display',
            'started_offset_ms' => 2000,
        ],
        $this->participant,
    );
    $missingParticipant = createMeetingFeedEvent(
        $this->capture,
        'feed-missing-participant',
        'participant.join',
        [
            'provider_participant_id' => 'not-persisted',
            'display_name' => 'Untrusted Name',
        ],
    );
    $incompleteFinal = createMeetingFeedEvent(
        $this->capture,
        'feed-incomplete-final',
        'transcript.final',
        [
            'utterance_key' => 'not-persisted:3000',
            'text' => 'must not display or analyze',
            'started_offset_ms' => 3000,
        ],
    );

    $response = $this->getJson("/meeting-captures/{$this->capture->id}/events?after=0")
        ->assertOk()
        ->assertJsonCount(4, 'events')
        ->assertJsonPath('events.0.cursor', $emptyFinal->id)
        ->assertJsonPath('events.0.type', 'capture.status')
        ->assertJsonPath('events.1.cursor', $ignoredPartial->id)
        ->assertJsonPath('events.1.type', 'capture.status')
        ->assertJsonPath('events.2.cursor', $missingParticipant->id)
        ->assertJsonPath('events.2.type', 'capture.status')
        ->assertJsonPath('events.3.cursor', $incompleteFinal->id)
        ->assertJsonPath('events.3.type', 'capture.status')
        ->assertJsonPath('next_cursor', $incompleteFinal->id)
        ->assertJsonPath('has_more', false);

    expect($response->getContent())
        ->not->toContain('late text that must not display')
        ->not->toContain('must not display or analyze')
        ->not->toContain('Untrusted Name');
});

it('returns sanitized capture failures without raw provider text', function () {
    $this->capture->update([
        'status' => MeetingCaptureStatus::Failed,
        'failure_code' => 'provider fatal / secret',
        'failure_message' => 'Raw provider failure with private meeting details.',
    ]);
    createMeetingFeedEvent(
        $this->capture,
        'feed-private-failure',
        'bot.fatal',
        [
            'capture_status' => 'failed',
            'failure_code' => 'provider fatal / secret',
            'failure_message' => 'Raw provider failure with private meeting details.',
        ],
    );

    $response = $this->getJson("/meeting-captures/{$this->capture->id}/events")
        ->assertOk()
        ->assertJsonPath('events.0.type', 'capture.error')
        ->assertJsonPath('events.0.code', 'recall_bot_failed')
        ->assertJsonPath('events.0.message', 'Meeting capture failed.')
        ->assertJsonPath('capture.failure_code', 'recall_bot_failed')
        ->assertJsonPath('capture.failure_message', 'Meeting capture failed.');

    expect($response->getContent())
        ->not->toContain('Raw provider failure')
        ->not->toContain('private meeting details');
});
