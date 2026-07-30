<?php

use App\Enums\AnalysisDeliveryStatus;
use App\Enums\MeetingCaptureStatus;
use App\Enums\MeetingProvider;
use App\Enums\SalesRole;
use App\Models\ConversationSession;
use App\Models\ConversationTranscript;
use App\Models\MeetingAnalysisDelivery;
use App\Models\MeetingCaptureSession;
use App\Models\MeetingParticipant;
use App\Models\ProviderEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('meeting capture persistence tables, columns, and named unique indexes exist', function () {
    expect(Schema::hasTable('meeting_capture_sessions'))->toBeTrue()
        ->and(Schema::hasTable('meeting_participants'))->toBeTrue()
        ->and(Schema::hasTable('provider_events'))->toBeTrue()
        ->and(Schema::hasTable('meeting_analysis_deliveries'))->toBeTrue()
        ->and(Schema::hasColumns('meeting_capture_sessions', [
            'id', 'conversation_session_id', 'provider', 'provider_bot_id', 'platform', 'status',
            'meeting_url_hash', 'idempotency_key', 'failure_code', 'failure_message', 'started_at', 'ended_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('meeting_participants', [
            'id', 'meeting_capture_session_id', 'provider_participant_id', 'display_name', 'email', 'email_hash',
            'is_host', 'is_bot', 'sales_role',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('provider_events', [
            'id', 'meeting_capture_session_id', 'provider', 'provider_webhook_id', 'provider_event_type',
            'provider_event_id', 'provider_utterance_key', 'meeting_participant_id', 'payload',
            'provider_occurred_at', 'received_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('meeting_analysis_deliveries', [
            'id', 'meeting_capture_session_id', 'conversation_transcript_id', 'status', 'lease_token', 'leased_at',
            'attempts', 'last_error', 'completed_at', 'completed_lease_token_hash', 'evidence_snapshot',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('conversation_transcripts', [
            'meeting_capture_session_id', 'meeting_participant_id', 'provider', 'provider_item_id',
            'started_offset_ms', 'ended_offset_ms',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('conversation_insights', 'semantic_key'))->toBeTrue()
        ->and(Schema::hasIndex(
            'conversation_transcripts',
            'conversation_transcripts_session_provider_item_unique',
            'unique',
        ))->toBeTrue()
        ->and(Schema::hasIndex(
            'conversation_insights',
            'conversation_insights_session_semantic_key_unique',
            'unique',
        ))->toBeTrue()
        ->and(Schema::hasIndex(
            'provider_events',
            'provider_events_capture_type_utterance_index',
        ))->toBeTrue();
});

test('conversation transcripts accept Recall unknown speakers', function () {
    $session = ConversationSession::factory()->create();

    ConversationTranscript::query()->create([
        'session_id' => $session->id,
        'speaker' => 'unknown',
        'text' => 'Unattributed turn',
        'spoken_at' => now(),
        'order_index' => 1,
    ]);

    expect(ConversationTranscript::query()->value('speaker'))->toBe('unknown');
});

test('rolling back and reapplying provider metadata preserves Recall speakers on default SQLite', function () {
    $session = ConversationSession::factory()->create();
    ConversationTranscript::query()->create([
        'session_id' => $session->id,
        'speaker' => 'unknown',
        'text' => 'Unattributed turn',
        'spoken_at' => now(),
        'order_index' => 1,
    ]);
    ConversationTranscript::query()->create([
        'session_id' => $session->id,
        'speaker' => 'bot',
        'text' => 'Bot turn',
        'spoken_at' => now(),
        'order_index' => 2,
    ]);

    try {
        expect(Artisan::call('migrate:rollback', [
            '--path' => providerMetadataMigrationPath(),
            '--force' => true,
        ]))->toBe(0)
            ->and(DB::table('conversation_transcripts')
                ->whereIn('text', ['Unattributed turn', 'Bot turn'])
                ->orderBy('text')
                ->pluck('speaker')
                ->all())->toBe(['system', 'system']);
    } finally {
        $reapplyExitCode = Artisan::call('migrate', [
            '--path' => providerMetadataMigrationPath(),
            '--force' => true,
        ]);
    }

    expect($reapplyExitCode)->toBe(0)
        ->and(Schema::hasColumn('conversation_transcripts', 'provider_item_id'))->toBeTrue();
});

test('meeting provider identifiers are deduplicated while transcript provider items are scoped to a conversation', function () {
    $session = ConversationSession::factory()->create();
    $otherSession = ConversationSession::factory()->create();
    $capture = createMeetingCapture($session);

    MeetingParticipant::query()->create([
        'meeting_capture_session_id' => $capture->id,
        'provider_participant_id' => 'participant-1',
        'is_bot' => false,
        'sales_role' => SalesRole::Customer,
    ]);

    expect(fn () => MeetingParticipant::query()->create([
        'meeting_capture_session_id' => $capture->id,
        'provider_participant_id' => 'participant-1',
        'is_bot' => false,
        'sales_role' => SalesRole::Customer,
    ]))->toThrow(QueryException::class);

    ProviderEvent::query()->create([
        'meeting_capture_session_id' => $capture->id,
        'provider' => MeetingProvider::Recall,
        'provider_webhook_id' => 'webhook-1',
        'provider_event_type' => 'transcript.data',
        'payload' => ['event' => 'transcript.data'],
        'received_at' => now(),
    ]);

    expect(fn () => ProviderEvent::query()->create([
        'meeting_capture_session_id' => $capture->id,
        'provider' => MeetingProvider::Recall,
        'provider_webhook_id' => 'webhook-1',
        'provider_event_type' => 'transcript.data',
        'payload' => ['event' => 'transcript.data'],
        'received_at' => now(),
    ]))->toThrow(QueryException::class);

    $transcript = transcriptFor($session, 'item-1', 1);

    expect(fn () => transcriptFor($session, 'item-1', 2))->toThrow(QueryException::class);

    $otherTranscript = transcriptFor($otherSession, 'item-1', 1);

    expect($transcript->provider_item_id)->toBe('item-1')
        ->and($otherTranscript->provider_item_id)->toBe('item-1');
});

test('meeting participant email is encrypted at rest and model casts and relationships resolve', function () {
    $session = ConversationSession::factory()->create();
    $capture = createMeetingCapture($session);
    $participant = MeetingParticipant::query()->create([
        'meeting_capture_session_id' => $capture->id,
        'provider_participant_id' => 'participant-email',
        'email' => 'buyer@example.test',
        'is_bot' => false,
        'sales_role' => SalesRole::Customer,
    ]);
    $transcript = transcriptFor($session, 'item-email', 1, $capture, $participant);
    $event = ProviderEvent::query()->create([
        'meeting_capture_session_id' => $capture->id,
        'provider' => MeetingProvider::Recall,
        'provider_webhook_id' => 'webhook-email',
        'provider_event_type' => 'transcript.data',
        'meeting_participant_id' => $participant->id,
        'payload' => ['event' => 'transcript.data'],
        'provider_occurred_at' => now()->subSecond(),
        'received_at' => now(),
    ]);
    $delivery = MeetingAnalysisDelivery::query()->create([
        'meeting_capture_session_id' => $capture->id,
        'conversation_transcript_id' => $transcript->id,
        'status' => AnalysisDeliveryStatus::Pending,
        'evidence_snapshot' => [
            'current_provider_item_id' => 'item-email',
            'prior_provider_item_ids' => ['item-prior'],
        ],
    ]);

    $storedEmail = DB::table('meeting_participants')->whereKey($participant->id)->value('email');
    $freshEvent = $event->fresh();

    expect($storedEmail)->not->toBe('buyer@example.test')
        ->and($participant->fresh()->email)->toBe('buyer@example.test')
        ->and($capture->fresh()->provider)->toBe(MeetingProvider::Recall)
        ->and($capture->fresh()->status)->toBe(MeetingCaptureStatus::Creating)
        ->and($participant->fresh()->sales_role)->toBe(SalesRole::Customer)
        ->and($delivery->fresh()->status)->toBe(AnalysisDeliveryStatus::Pending)
        ->and($delivery->fresh()->evidence_snapshot)->toBe([
            'current_provider_item_id' => 'item-email',
            'prior_provider_item_ids' => ['item-prior'],
        ])
        ->and($freshEvent->provider)->toBe(MeetingProvider::Recall)
        ->and($freshEvent->payload)->toBeArray()
        ->and($freshEvent->provider_occurred_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($freshEvent->received_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($session->captures()->first()?->id)->toBe($capture->id)
        ->and($capture->participants()->first()?->id)->toBe($participant->id)
        ->and($capture->events()->first()?->id)->toBe($event->id)
        ->and($capture->analysisDeliveries()->first()?->id)->toBe($delivery->id)
        ->and($freshEvent->capture?->id)->toBe($capture->id)
        ->and($freshEvent->participant?->id)->toBe($participant->id)
        ->and($transcript->fresh()->capture?->id)->toBe($capture->id)
        ->and($transcript->fresh()->participant?->id)->toBe($participant->id)
        ->and($transcript->fresh()->analysisDelivery?->id)->toBe($delivery->id);
});

test('meeting capture migrations apply to the nativephp SQLite connection', function () {
    config(['database.connections.nativephp.database' => ':memory:']);
    DB::purge('nativephp');

    expect(Artisan::call('migrate', ['--database' => 'nativephp', '--force' => true]))->toBe(0)
        ->and(Schema::connection('nativephp')->hasTable('meeting_capture_sessions'))->toBeTrue()
        ->and(Schema::connection('nativephp')->hasTable('meeting_participants'))->toBeTrue()
        ->and(Schema::connection('nativephp')->hasTable('provider_events'))->toBeTrue()
        ->and(Schema::connection('nativephp')->hasTable('meeting_analysis_deliveries'))->toBeTrue()
        ->and(Schema::connection('nativephp')->hasColumn(
            'meeting_analysis_deliveries',
            'completed_lease_token_hash',
        ))->toBeTrue()
        ->and(Schema::connection('nativephp')->hasColumn(
            'meeting_analysis_deliveries',
            'evidence_snapshot',
        ))->toBeTrue()
        ->and(Schema::connection('nativephp')->hasColumn('provider_events', 'provider_utterance_key'))->toBeTrue()
        ->and(Schema::connection('nativephp')->hasIndex(
            'provider_events',
            'provider_events_capture_type_utterance_index',
        ))->toBeTrue()
        ->and(Schema::connection('nativephp')->hasIndex(
            'conversation_transcripts',
            'conversation_transcripts_session_provider_item_unique',
            'unique',
        ))->toBeTrue()
        ->and(Schema::connection('nativephp')->hasIndex(
            'conversation_insights',
            'conversation_insights_session_semantic_key_unique',
            'unique',
        ))->toBeTrue();
});

test('evidence snapshot migration rolls back and reapplies on default SQLite', function () {
    try {
        expect(Artisan::call('migrate:rollback', [
            '--path' => evidenceSnapshotMigrationPath(),
            '--force' => true,
        ]))->toBe(0)
            ->and(Schema::hasColumn(
                'meeting_analysis_deliveries',
                'evidence_snapshot',
            ))->toBeFalse();
    } finally {
        $reapplyExitCode = Artisan::call('migrate', [
            '--path' => evidenceSnapshotMigrationPath(),
            '--force' => true,
        ]);
    }

    expect($reapplyExitCode)->toBe(0)
        ->and(Schema::hasColumn(
            'meeting_analysis_deliveries',
            'evidence_snapshot',
        ))->toBeTrue();
});

test('evidence snapshot migration rolls back and reapplies on nativephp SQLite', function () {
    config(['database.connections.nativephp.database' => ':memory:']);
    DB::purge('nativephp');
    Artisan::call('migrate', ['--database' => 'nativephp', '--force' => true]);

    try {
        expect(Artisan::call('migrate:rollback', [
            '--database' => 'nativephp',
            '--path' => evidenceSnapshotMigrationPath(),
            '--force' => true,
        ]))->toBe(0)
            ->and(Schema::connection('nativephp')->hasColumn(
                'meeting_analysis_deliveries',
                'evidence_snapshot',
            ))->toBeFalse();
    } finally {
        $reapplyExitCode = Artisan::call('migrate', [
            '--database' => 'nativephp',
            '--path' => evidenceSnapshotMigrationPath(),
            '--force' => true,
        ]);
    }

    expect($reapplyExitCode)->toBe(0)
        ->and(Schema::connection('nativephp')->hasColumn(
            'meeting_analysis_deliveries',
            'evidence_snapshot',
        ))->toBeTrue();
});

test('rolling back and reapplying provider metadata preserves Recall speakers on nativephp SQLite', function () {
    config(['database.connections.nativephp.database' => ':memory:']);
    DB::purge('nativephp');
    Artisan::call('migrate', ['--database' => 'nativephp', '--force' => true]);

    $connection = DB::connection('nativephp');
    $sessionId = $connection->table('conversation_sessions')->insertGetId([
        'started_at' => now(),
        'duration_seconds' => 0,
    ]);
    $connection->table('conversation_transcripts')->insertGetId([
        'session_id' => $sessionId,
        'speaker' => 'unknown',
        'text' => 'Unattributed turn',
        'spoken_at' => now(),
        'order_index' => 1,
    ]);
    $connection->table('conversation_transcripts')->insertGetId([
        'session_id' => $sessionId,
        'speaker' => 'bot',
        'text' => 'Bot turn',
        'spoken_at' => now(),
        'order_index' => 2,
    ]);

    try {
        expect(Artisan::call('migrate:rollback', [
            '--database' => 'nativephp',
            '--path' => providerMetadataMigrationPath(),
            '--force' => true,
        ]))->toBe(0)
            ->and($connection->table('conversation_transcripts')
                ->whereIn('text', ['Unattributed turn', 'Bot turn'])
                ->orderBy('text')
                ->pluck('speaker')
                ->all())->toBe(['system', 'system']);
    } finally {
        $reapplyExitCode = Artisan::call('migrate', [
            '--database' => 'nativephp',
            '--path' => providerMetadataMigrationPath(),
            '--force' => true,
        ]);
    }

    expect($reapplyExitCode)->toBe(0)
        ->and(Schema::connection('nativephp')->hasColumn('conversation_transcripts', 'provider_item_id'))->toBeTrue();
});

function createMeetingCapture(ConversationSession $session): MeetingCaptureSession
{
    return MeetingCaptureSession::query()->create([
        'conversation_session_id' => $session->id,
        'provider' => MeetingProvider::Recall,
        'status' => MeetingCaptureStatus::Creating,
        'idempotency_key' => (string) Str::uuid(),
    ]);
}

function transcriptFor(
    ConversationSession $session,
    string $providerItemId,
    int $orderIndex,
    ?MeetingCaptureSession $capture = null,
    ?MeetingParticipant $participant = null,
): ConversationTranscript {
    return ConversationTranscript::query()->create([
        'session_id' => $session->id,
        'meeting_capture_session_id' => $capture?->id,
        'meeting_participant_id' => $participant?->id,
        'speaker' => 'customer',
        'text' => 'Provider transcript item',
        'spoken_at' => now(),
        'provider' => MeetingProvider::Recall,
        'provider_item_id' => $providerItemId,
        'order_index' => $orderIndex,
    ]);
}

function providerMetadataMigrationPath(): string
{
    return 'database/migrations/2026_07_27_000002_add_provider_metadata_to_conversation_transcripts.php';
}

function evidenceSnapshotMigrationPath(): string
{
    return 'database/migrations/2026_07_27_000005_add_evidence_snapshot_to_meeting_analysis_deliveries.php';
}
