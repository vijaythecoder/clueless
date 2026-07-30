<?php

use App\Enums\MeetingCaptureStatus;
use App\Enums\MeetingProvider;
use App\Enums\SalesRole;
use App\Models\ConversationSession;
use App\Models\ConversationTranscript;
use App\Models\MeetingCaptureSession;
use App\Models\MeetingParticipant;

beforeEach(function () {
    $conversation = ConversationSession::factory()->ongoing()->create();
    $this->capture = MeetingCaptureSession::query()->create([
        'conversation_session_id' => $conversation->id,
        'provider' => MeetingProvider::Recall,
        'platform' => 'teams',
        'status' => MeetingCaptureStatus::Active,
        'idempotency_key' => (string) str()->uuid(),
    ]);
    $this->salesperson = MeetingParticipant::query()->create([
        'meeting_capture_session_id' => $this->capture->id,
        'provider_participant_id' => 'person-a',
        'display_name' => 'Sales Rep',
        'is_bot' => false,
        'sales_role' => SalesRole::Unknown,
    ]);
    $this->customer = MeetingParticipant::query()->create([
        'meeting_capture_session_id' => $this->capture->id,
        'provider_participant_id' => 'person-b',
        'display_name' => 'Customer',
        'is_bot' => false,
        'sales_role' => SalesRole::Unknown,
    ]);
    $this->bot = MeetingParticipant::query()->create([
        'meeting_capture_session_id' => $this->capture->id,
        'provider_participant_id' => 'bot',
        'display_name' => 'Clueless Copilot',
        'is_bot' => true,
        'sales_role' => SalesRole::Bot,
    ]);

    foreach ([$this->salesperson, $this->customer, $this->bot] as $index => $participant) {
        ConversationTranscript::query()->create([
            'session_id' => $conversation->id,
            'speaker' => $participant->sales_role->value,
            'source_stream' => 'recall',
            'text' => "Turn {$index}",
            'spoken_at' => now(),
            'status' => 'final',
            'order_index' => $index + 1,
            'meeting_capture_session_id' => $this->capture->id,
            'meeting_participant_id' => $participant->id,
            'provider' => MeetingProvider::Recall,
            'provider_item_id' => "role-turn-{$index}",
            'started_offset_ms' => ($index + 1) * 100,
        ]);
    }
});

it('assigns salesperson and customer atomically and backfills transcripts', function () {
    $response = $this->patchJson(
        "/meeting-captures/{$this->capture->id}/participants/{$this->salesperson->id}",
        ['sales_role' => 'salesperson'],
    )->assertOk()
        ->assertJsonCount(3, 'participants')
        ->assertJsonPath('participants.0.sales_role', 'salesperson')
        ->assertJsonPath('participants.1.sales_role', 'customer')
        ->assertJsonPath('participants.2.sales_role', 'bot');

    expect($response->getContent())->not->toContain('@')
        ->and($this->salesperson->fresh()->sales_role)->toBe(SalesRole::Salesperson)
        ->and($this->customer->fresh()->sales_role)->toBe(SalesRole::Customer)
        ->and($this->bot->fresh()->sales_role)->toBe(SalesRole::Bot)
        ->and($this->salesperson->transcripts()->sole()->speaker)->toBe('salesperson')
        ->and($this->customer->transcripts()->sole()->speaker)->toBe('customer')
        ->and($this->bot->transcripts()->sole()->speaker)->toBe('bot');
});

it('rejects bot and cross-capture assignment', function () {
    $this->patchJson(
        "/meeting-captures/{$this->capture->id}/participants/{$this->bot->id}",
        ['sales_role' => 'salesperson'],
    )->assertUnprocessable();

    $otherConversation = ConversationSession::factory()->ongoing()->create();
    $otherCapture = MeetingCaptureSession::query()->create([
        'conversation_session_id' => $otherConversation->id,
        'provider' => MeetingProvider::Recall,
        'status' => MeetingCaptureStatus::Active,
        'idempotency_key' => (string) str()->uuid(),
    ]);
    $outsider = MeetingParticipant::query()->create([
        'meeting_capture_session_id' => $otherCapture->id,
        'provider_participant_id' => 'outsider',
        'is_bot' => false,
        'sales_role' => SalesRole::Unknown,
    ]);

    $this->patchJson(
        "/meeting-captures/{$this->capture->id}/participants/{$outsider->id}",
        ['sales_role' => 'salesperson'],
    )->assertNotFound();

    $this->patchJson(
        "/meeting-captures/{$this->capture->id}/participants/{$this->salesperson->id}",
        ['sales_role' => 'customer'],
    )->assertUnprocessable();
});
