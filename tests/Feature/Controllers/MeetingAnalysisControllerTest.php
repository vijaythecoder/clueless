<?php

use App\Enums\AnalysisDeliveryStatus;
use App\Enums\MeetingCaptureStatus;
use App\Enums\MeetingProvider;
use App\Enums\SalesRole;
use App\Jobs\AnalyzeRecallCapture;
use App\Models\ConversationInsight;
use App\Models\ConversationSession;
use App\Models\ConversationTranscript;
use App\Models\MeetingAnalysisDelivery;
use App\Models\MeetingCaptureSession;
use App\Models\MeetingParticipant;
use App\Models\SecureSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Carbon::setTestNow('2026-07-27 12:00:00');
    $conversation = ConversationSession::factory()->ongoing()->create();
    $this->capture = MeetingCaptureSession::query()->create([
        'conversation_session_id' => $conversation->id,
        'provider' => MeetingProvider::Recall,
        'status' => MeetingCaptureStatus::Active,
        'idempotency_key' => (string) str()->uuid(),
    ]);
    $this->salesperson = MeetingParticipant::query()->create([
        'meeting_capture_session_id' => $this->capture->id,
        'provider_participant_id' => 'salesperson',
        'display_name' => 'Vijay',
        'is_bot' => false,
        'sales_role' => SalesRole::Salesperson,
    ]);
    $this->customer = MeetingParticipant::query()->create([
        'meeting_capture_session_id' => $this->capture->id,
        'provider_participant_id' => 'customer',
        'display_name' => 'Ada',
        'is_bot' => false,
        'sales_role' => SalesRole::Customer,
    ]);
    $this->unknown = MeetingParticipant::query()->create([
        'meeting_capture_session_id' => $this->capture->id,
        'provider_participant_id' => 'unknown',
        'display_name' => 'Unassigned',
        'is_bot' => false,
        'sales_role' => SalesRole::Unknown,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function createAnalysisTurn(
    MeetingCaptureSession $capture,
    MeetingParticipant $participant,
    string $itemId,
    int $offset,
    AnalysisDeliveryStatus $status = AnalysisDeliveryStatus::Pending,
    int $attempts = 0,
    ?Carbon $leasedAt = null,
): MeetingAnalysisDelivery {
    $transcript = ConversationTranscript::query()->create([
        'session_id' => $capture->conversation_session_id,
        'speaker' => $participant->sales_role->value,
        'source_stream' => 'recall',
        'text' => "Transcript {$itemId}",
        'spoken_at' => now()->addMilliseconds($offset),
        'status' => 'final',
        'order_index' => $offset,
        'meeting_capture_session_id' => $capture->id,
        'meeting_participant_id' => $participant->id,
        'provider' => MeetingProvider::Recall,
        'provider_item_id' => $itemId,
        'started_offset_ms' => $offset,
        'ended_offset_ms' => $offset + 50,
    ]);

    return MeetingAnalysisDelivery::query()->create([
        'meeting_capture_session_id' => $capture->id,
        'conversation_transcript_id' => $transcript->id,
        'status' => $status,
        'lease_token' => $status === AnalysisDeliveryStatus::Processing ? (string) str()->uuid() : null,
        'leased_at' => $leasedAt,
        'attempts' => $attempts,
    ]);
}

it('claims only role-resolved finalized turns in provider chronology', function () {
    createAnalysisTurn($this->capture, $this->customer, 'later', 300);
    createAnalysisTurn($this->capture, $this->salesperson, 'first', 100);
    createAnalysisTurn($this->capture, $this->unknown, 'unknown', 50);

    $response = $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/claim",
        ['through_cursor' => 1000],
    )->assertOk()
        ->assertJsonCount(2, 'deliveries')
        ->assertJsonPath('deliveries.0.provider_item_id', 'first')
        ->assertJsonPath('deliveries.0.display_name', 'Vijay')
        ->assertJsonPath('deliveries.0.sales_role', 'salesperson')
        ->assertJsonPath('deliveries.1.provider_item_id', 'later')
        ->assertJsonPath('deliveries.1.display_name', 'Ada')
        ->assertJsonPath('counts.pending', 1)
        ->assertJsonPath('counts.processing', 2);

    expect($response->json('deliveries.0.lease_token'))->toBeString()
        ->and($response->json('deliveries.1.context.0.provider_item_id'))->toBe('first')
        ->and(MeetingAnalysisDelivery::query()->where('status', AnalysisDeliveryStatus::Processing)->count())->toBe(2);
});

it('reclaims expired leases and fails after three attempts', function () {
    $delivery = createAnalysisTurn(
        $this->capture,
        $this->customer,
        'retry',
        100,
        AnalysisDeliveryStatus::Processing,
        1,
        now()->subSeconds(31),
    );

    $second = $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/claim",
        ['through_cursor' => 1000],
    )->assertOk()->json('deliveries.0.lease_token');

    expect($delivery->fresh()->attempts)->toBe(2);
    $delivery->fresh()->update(['leased_at' => now()->subSeconds(31)]);

    $thirdResponse = $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/claim",
        ['through_cursor' => 1000],
    )->assertOk()->assertJsonCount(1, 'deliveries');
    $third = $thirdResponse->json('deliveries.0.lease_token');

    expect($third)->not->toBe($second)
        ->and($delivery->fresh()->attempts)->toBe(3);
    $delivery->fresh()->update(['leased_at' => now()->subSeconds(31)]);

    $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/claim",
        ['through_cursor' => 1000],
    )->assertOk()
        ->assertJsonCount(0, 'deliveries')
        ->assertJsonPath('counts.failed', 1);

    expect($delivery->fresh()->status)->toBe(AnalysisDeliveryStatus::Failed)
        ->and($delivery->fresh()->last_error)->toBe('analysis_attempts_exhausted')
        ->and($delivery->fresh()->lease_token)->toBeNull();
});

it('acknowledges only matching leases', function () {
    $delivery = createAnalysisTurn($this->capture, $this->customer, 'ack', 100);
    $claim = $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/claim",
        ['through_cursor' => 1000],
    )->assertOk()->json('deliveries.0');

    $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/{$delivery->id}/ack",
        ['lease_token' => (string) str()->uuid(), 'status' => 'completed'],
    )->assertConflict();

    $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/{$delivery->id}/ack",
        [
            'lease_token' => $claim['lease_token'],
            'status' => 'failed',
            'error_code' => 'Copilot analysis failed !!!',
        ],
    )->assertOk()
        ->assertJsonPath('status', 'failed');

    expect($delivery->fresh()->status)->toBe(AnalysisDeliveryStatus::Failed)
        ->and($delivery->fresh()->last_error)->toBe('copilot_analysis_failed');

    $otherConversation = ConversationSession::factory()->ongoing()->create();
    $otherCapture = MeetingCaptureSession::query()->create([
        'conversation_session_id' => $otherConversation->id,
        'provider' => MeetingProvider::Recall,
        'status' => MeetingCaptureStatus::Active,
        'idempotency_key' => (string) str()->uuid(),
    ]);

    $this->postJson(
        "/meeting-captures/{$otherCapture->id}/analysis-deliveries/{$delivery->id}/ack",
        ['lease_token' => $claim['lease_token'], 'status' => 'completed'],
    )->assertNotFound();
});

it('makes completed acknowledgement idempotent', function () {
    $delivery = createAnalysisTurn($this->capture, $this->salesperson, 'complete', 100);
    $claim = $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/claim",
        ['through_cursor' => 1000],
    )->assertOk()->json('deliveries.0');
    $payload = ['lease_token' => $claim['lease_token'], 'status' => 'completed'];
    $url = "/meeting-captures/{$this->capture->id}/analysis-deliveries/{$delivery->id}/ack";

    $this->postJson($url, $payload)->assertOk()->assertJsonPath('status', 'completed');
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('status', 'completed');

    expect($delivery->fresh()->status)->toBe(AnalysisDeliveryStatus::Completed)
        ->and($delivery->fresh()->completed_at)->not->toBeNull()
        ->and($delivery->fresh()->lease_token)->toBeNull()
        ->and($delivery->fresh()->completed_lease_token_hash)->toBe(hash('sha256', $claim['lease_token']));

    $this->postJson($url, [
        'lease_token' => (string) str()->uuid(),
        'status' => 'completed',
    ])->assertConflict();
    $this->postJson($url, [
        'lease_token' => $claim['lease_token'],
        'status' => 'failed',
        'error_code' => 'must_not_replace_completion',
    ])->assertConflict();
});

it('returns only strictly prior context capped at eight turns', function () {
    foreach (range(0, 9) as $index) {
        createAnalysisTurn(
            $this->capture,
            $index % 2 === 0 ? $this->salesperson : $this->customer,
            "context-{$index}",
            ($index + 1) * 10,
        );
    }

    $deliveries = $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/claim",
        ['through_cursor' => 100],
    )->assertOk()
        ->assertJsonCount(10, 'deliveries')
        ->json('deliveries');

    expect(array_column($deliveries[0]['context'], 'provider_item_id'))->toBe([])
        ->and(array_column($deliveries[1]['context'], 'provider_item_id'))->toBe(['context-0'])
        ->and(array_column($deliveries[9]['context'], 'provider_item_id'))->toBe([
            'context-1',
            'context-2',
            'context-3',
            'context-4',
            'context-5',
            'context-6',
            'context-7',
            'context-8',
        ]);
});

it('freezes claim evidence across late out-of-order transcripts and lease reclaims', function () {
    foreach (range(0, 8) as $index) {
        createAnalysisTurn(
            $this->capture,
            $index % 2 === 0 ? $this->salesperson : $this->customer,
            "frozen-prior-{$index}",
            ($index + 1) * 10,
        );
    }
    $delivery = createAnalysisTurn(
        $this->capture,
        $this->customer,
        'frozen-current',
        100,
    );

    $firstClaim = $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/claim",
        ['through_cursor' => 100],
    )->assertOk()->json('deliveries');
    $firstDelivery = collect($firstClaim)->firstWhere('id', $delivery->id);
    $originalPriorIds = array_column($firstDelivery['context'], 'provider_item_id');

    expect($originalPriorIds)->toBe([
        'frozen-prior-1',
        'frozen-prior-2',
        'frozen-prior-3',
        'frozen-prior-4',
        'frozen-prior-5',
        'frozen-prior-6',
        'frozen-prior-7',
        'frozen-prior-8',
    ])->and($delivery->fresh()->evidence_snapshot)->toBe([
        'current_provider_item_id' => 'frozen-current',
        'prior_provider_item_ids' => $originalPriorIds,
    ]);

    ConversationTranscript::query()->create([
        'session_id' => $this->capture->conversation_session_id,
        'speaker' => SalesRole::Customer,
        'source_stream' => 'recall',
        'text' => 'Late out-of-order transcript',
        'spoken_at' => now(),
        'status' => 'final',
        'order_index' => 101,
        'meeting_capture_session_id' => $this->capture->id,
        'meeting_participant_id' => $this->customer->id,
        'provider' => MeetingProvider::Recall,
        'provider_item_id' => 'late-unsupplied',
        'started_offset_ms' => 95,
        'ended_offset_ms' => 99,
    ]);
    $delivery->fresh()->update(['leased_at' => now()->subSeconds(31)]);

    $reclaim = $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/claim",
        ['through_cursor' => 101],
    )->assertOk()
        ->assertJsonCount(1, 'deliveries')
        ->json('deliveries.0');

    expect(array_column($reclaim['context'], 'provider_item_id'))->toBe($originalPriorIds)
        ->and($delivery->fresh()->evidence_snapshot)->toBe([
            'current_provider_item_id' => 'frozen-current',
            'prior_provider_item_ids' => $originalPriorIds,
        ]);

    $validPayload = [
        'insight_type' => 'pain_point',
        'tool_call_id' => 'call-frozen-valid',
        'card_type' => 'pain_point',
        'analysis_delivery_id' => $delivery->id,
        'evidence_item_ids' => ['frozen-current', 'frozen-prior-1'],
        'data' => [
            'text' => 'The original evidence remains valid.',
            'category' => null,
            'severity' => 'medium',
        ],
        'captured_at' => now()->timestamp * 1000,
    ];

    $this->postJson(
        "/conversations/{$this->capture->conversation_session_id}/insight",
        $validPayload,
    )->assertOk();
    $this->postJson(
        "/conversations/{$this->capture->conversation_session_id}/insight",
        [
            ...$validPayload,
            'tool_call_id' => 'call-frozen-late',
            'evidence_item_ids' => ['frozen-current', 'late-unsupplied'],
        ],
    )->assertUnprocessable()
        ->assertJsonValidationErrors(['evidence_item_ids']);
});

it('fails closed for malformed evidence snapshots on reclaim and persistence', function (
    string $case,
) {
    $prior = createSnapshotPriorTranscript($this->capture, $this->customer, 'strict-prior', 10);
    $delivery = createAnalysisTurn(
        $this->capture,
        $this->customer,
        'strict-current',
        20,
    );
    $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/claim",
        ['through_cursor' => 20],
    )->assertOk();

    $snapshot = [
        'current_provider_item_id' => 'strict-current',
        'prior_provider_item_ids' => ['strict-prior'],
    ];

    switch ($case) {
        case 'blank current':
            $snapshot['current_provider_item_id'] = '   ';
            break;
        case 'non-string current':
            $snapshot['current_provider_item_id'] = 123;
            break;
        case 'missing current':
            unset($snapshot['current_provider_item_id']);
            break;
        case 'missing prior list':
            unset($snapshot['prior_provider_item_ids']);
            break;
        case 'unexpected key':
            $snapshot['unexpected'] = true;
            break;
        case 'non-list prior':
            $snapshot['prior_provider_item_ids'] = ['first' => 'strict-prior'];
            break;
        case 'blank prior':
            $snapshot['prior_provider_item_ids'] = ['   '];
            break;
        case 'non-string prior':
            $snapshot['prior_provider_item_ids'] = [123];
            break;
        case 'duplicate current':
            $snapshot['prior_provider_item_ids'] = ['strict-current'];
            break;
        case 'duplicate prior':
            $snapshot['prior_provider_item_ids'] = ['strict-prior', 'strict-prior'];
            break;
        case 'too many prior':
            $snapshot['prior_provider_item_ids'] = array_map(
                fn (int $index): string => "excess-{$index}",
                range(0, 8),
            );
            break;
        case 'current mismatch':
            createSnapshotPriorTranscript(
                $this->capture,
                $this->salesperson,
                'strict-other-current',
                15,
            );
            $snapshot['current_provider_item_id'] = 'strict-other-current';
            break;
        case 'wrong capture':
            $prior->update([
                'meeting_capture_session_id' => MeetingCaptureSession::query()->create([
                    'conversation_session_id' => $this->capture->conversation_session_id,
                    'provider' => MeetingProvider::Recall,
                    'status' => MeetingCaptureStatus::Active,
                    'idempotency_key' => (string) str()->uuid(),
                ])->id,
            ]);
            break;
        case 'wrong session':
            $prior->update([
                'session_id' => ConversationSession::factory()->ongoing()->create()->id,
            ]);
            break;
        case 'wrong provider':
            $prior->update(['provider' => MeetingProvider::Local->value]);
            break;
        case 'not final':
            $prior->update(['status' => 'partial']);
            break;
        case 'unresolved role':
            $prior->update(['speaker' => SalesRole::Unknown->value]);
            break;
    }

    $delivery->fresh()->update([
        'evidence_snapshot' => $snapshot,
        'leased_at' => now()->subSeconds(31),
    ]);

    $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/claim",
        ['through_cursor' => 1000],
    )->assertOk()
        ->assertJsonCount(0, 'deliveries');

    $this->postJson(
        "/conversations/{$this->capture->conversation_session_id}/insight",
        [
            'insight_type' => 'pain_point',
            'tool_call_id' => "call-malformed-{$case}",
            'card_type' => 'pain_point',
            'analysis_delivery_id' => $delivery->id,
            'evidence_item_ids' => ['strict-current'],
            'data' => [
                'text' => 'Malformed evidence must fail closed.',
                'category' => null,
                'severity' => 'high',
            ],
            'captured_at' => now()->timestamp * 1000,
        ],
    )->assertUnprocessable()
        ->assertJsonValidationErrors(['evidence_item_ids']);
})->with([
    'blank current',
    'non-string current',
    'missing current',
    'missing prior list',
    'unexpected key',
    'non-list prior',
    'blank prior',
    'non-string prior',
    'duplicate current',
    'duplicate prior',
    'too many prior',
    'current mismatch',
    'wrong capture',
    'wrong session',
    'wrong provider',
    'not final',
    'unresolved role',
]);

it('withholds snapshots when a referenced transcript row is missing', function () {
    $prior = createSnapshotPriorTranscript($this->capture, $this->customer, 'missing-prior', 10);
    $delivery = createAnalysisTurn(
        $this->capture,
        $this->customer,
        'missing-current',
        20,
    );
    $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/claim",
        ['through_cursor' => 20],
    )->assertOk();

    expect($delivery->fresh()->evidence_snapshot)->toBe([
        'current_provider_item_id' => 'missing-current',
        'prior_provider_item_ids' => ['missing-prior'],
    ]);

    $prior->delete();
    $delivery->fresh()->update(['leased_at' => now()->subSeconds(31)]);

    $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/claim",
        ['through_cursor' => 1000],
    )->assertOk()
        ->assertJsonCount(0, 'deliveries');
    $this->postJson(
        "/conversations/{$this->capture->conversation_session_id}/insight",
        [
            'insight_type' => 'discussion_topic',
            'tool_call_id' => 'call-missing-row',
            'card_type' => 'discussion_topic',
            'analysis_delivery_id' => $delivery->id,
            'evidence_item_ids' => ['missing-current'],
            'data' => [
                'name' => 'Missing evidence',
                'sentiment' => 'neutral',
                'context' => 'A referenced row was deleted.',
            ],
            'captured_at' => now()->timestamp * 1000,
        ],
    )->assertUnprocessable()
        ->assertJsonValidationErrors(['evidence_item_ids']);
});

it('analyzes a claimed Recall delivery through Responses and persists cards before completion', function () {
    config()->set([
        'openai.recall_analysis.driver' => 'responses',
        'openai.recall_analysis.model' => 'gpt-5.6-luna',
        'openai.recall_analysis.retries' => 0,
    ]);
    SecureSetting::query()->create([
        'key' => SecureSetting::OPENAI_API_KEY,
        'value' => mockApiKey(),
    ]);
    $delivery = createAnalysisTurn($this->capture, $this->customer, 'responses-current', 100);
    $claim = $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/claim",
        ['through_cursor' => 100],
    )->assertOk()->json('deliveries.0');
    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_recall_1',
            'status' => 'completed',
            'output' => [[
                'type' => 'function_call',
                'name' => 'capture_pain_point',
                'call_id' => 'call_recall_pain_1',
                'arguments' => json_encode([
                    'text' => 'Manual reporting consumes the customer team.',
                    'category' => 'operations',
                    'severity' => 'high',
                    'evidence_item_ids' => ['responses-current'],
                ], JSON_THROW_ON_ERROR),
            ]],
        ]),
    ]);

    $url = "/meeting-captures/{$this->capture->id}/analysis-deliveries/{$delivery->id}/analyze";
    $payload = ['lease_token' => $claim['lease_token']];
    $response = $this->postJson($url, $payload)
        ->assertOk()
        ->assertJsonPath('response_id', 'resp_recall_1')
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('ui_tools.0.name', 'capture_pain_point')
        ->assertJsonPath('ui_tools.0.context.analysisDeliveryId', $delivery->id);

    expect($delivery->fresh()->status)->toBe(AnalysisDeliveryStatus::Completed)
        ->and(ConversationInsight::query()->count())->toBe(1)
        ->and(ConversationInsight::query()->first()->metadata)->toMatchArray([
            'analysis_delivery_id' => $delivery->id,
            'openai_response_id' => 'resp_recall_1',
            'analysis_driver' => 'responses',
        ]);

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $request->url() === 'https://api.openai.com/v1/responses'
            && $payload['model'] === 'gpt-5.6-luna'
            && $payload['reasoning']['effort'] === 'low'
            && $payload['store'] === false
            && $payload['parallel_tool_calls'] === true
            && collect($payload['tools'])->contains(
                fn (array $tool): bool => $tool['type'] === 'function'
                    && $tool['name'] === 'capture_pain_point',
            );
    });

    $this->postJson($url, $payload)
        ->assertOk()
        ->assertJsonPath('response_id', 'resp_recall_1')
        ->assertJsonPath('ui_tools.0.call_id', 'call_recall_pain_1');

    expect(Http::recorded())->toHaveCount(1)
        ->and(ConversationInsight::query()->count())->toBe(1)
        ->and($response->getContent())->not->toContain(mockApiKey());
});

it('rejects Responses tool calls that escape the immutable evidence snapshot', function () {
    config()->set([
        'openai.recall_analysis.driver' => 'responses',
        'openai.recall_analysis.retries' => 0,
    ]);
    SecureSetting::query()->create([
        'key' => SecureSetting::OPENAI_API_KEY,
        'value' => mockApiKey(),
    ]);
    $delivery = createAnalysisTurn($this->capture, $this->customer, 'responses-safe', 100);
    $claim = $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/claim",
        ['through_cursor' => 100],
    )->assertOk()->json('deliveries.0');
    Http::fake([
        '*' => Http::response([
            'id' => 'resp_recall_invalid',
            'status' => 'completed',
            'output' => [[
                'type' => 'function_call',
                'name' => 'capture_discussion_topic',
                'call_id' => 'call_invalid_evidence',
                'arguments' => json_encode([
                    'name' => 'Pricing',
                    'sentiment' => 'neutral',
                    'context' => 'Pricing was discussed.',
                    'evidence_item_ids' => ['foreign-turn'],
                ], JSON_THROW_ON_ERROR),
            ]],
        ]),
    ]);

    $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/{$delivery->id}/analyze",
        ['lease_token' => $claim['lease_token']],
    )->assertUnprocessable()
        ->assertJsonValidationErrors(['evidence_item_ids']);

    expect($delivery->fresh()->status)->toBe(AnalysisDeliveryStatus::Failed)
        ->and($delivery->fresh()->last_error)->toBe('responses_invalid_tool_call')
        ->and(ConversationInsight::query()->count())->toBe(0);
});

it('pages persisted Responses cards with an independent monotonic insight cursor', function () {
    config()->set('openai.recall_analysis.driver', 'responses');
    $delivery = createAnalysisTurn(
        $this->capture,
        $this->customer,
        'responses-feed',
        100,
        AnalysisDeliveryStatus::Completed,
    );
    $delivery->update([
        'evidence_snapshot' => [
            'current_provider_item_id' => 'responses-feed',
            'prior_provider_item_ids' => [],
        ],
        'completed_at' => now(),
    ]);
    $legacy = ConversationInsight::query()->create([
        'session_id' => $this->capture->conversation_session_id,
        'insight_type' => 'key_insight',
        'card_type' => 'legacy',
        'data' => ['text' => 'Older renderer card'],
        'captured_at' => now(),
    ]);
    $server = ConversationInsight::query()->create([
        'session_id' => $this->capture->conversation_session_id,
        'insight_type' => 'talk_track',
        'tool_call_id' => 'call_feed_talk_track',
        'card_type' => 'talk_track',
        'data' => [
            'text' => 'Ask how much time reporting takes today.',
            'reason' => 'Quantify impact',
            'priority' => 'high',
        ],
        'metadata' => [
            'analysis_delivery_id' => $delivery->id,
            'openai_response_id' => 'resp_feed',
            'analysis_driver' => 'responses',
        ],
        'captured_at' => now()->addSecond(),
    ]);

    $this->getJson("/meeting-captures/{$this->capture->id}/insights?after=0&limit=100")
        ->assertOk()
        ->assertJsonCount(1, 'ui_tools')
        ->assertJsonPath('ui_tools.0.name', 'suggest_talk_track')
        ->assertJsonPath('ui_tools.0.call_id', 'call_feed_talk_track')
        ->assertJsonPath('next_cursor', $server->id)
        ->assertJsonPath('counts.completed', 1);

    expect($legacy->id)->toBeLessThan($server->id);
});

it('drains eligible Recall deliveries in the queue without renderer claims', function () {
    config()->set([
        'openai.recall_analysis.driver' => 'responses',
        'openai.recall_analysis.retries' => 0,
    ]);
    SecureSetting::query()->create([
        'key' => SecureSetting::OPENAI_API_KEY,
        'value' => mockApiKey(),
    ]);
    $first = createAnalysisTurn($this->capture, $this->salesperson, 'queued-first', 100);
    $second = createAnalysisTurn($this->capture, $this->customer, 'queued-second', 200);
    $responseSequence = 0;
    Http::fake(function () use (&$responseSequence) {
        $responseSequence++;

        return Http::response([
            'id' => "resp_queue_{$responseSequence}",
            'status' => 'completed',
            'output' => [],
        ]);
    });

    AnalyzeRecallCapture::dispatchSync($this->capture->id);

    expect($first->fresh()->status)->toBe(AnalysisDeliveryStatus::Completed)
        ->and($second->fresh()->status)->toBe(AnalysisDeliveryStatus::Completed)
        ->and(Http::recorded())->toHaveCount(2)
        ->and(ConversationInsight::query()->count())->toBe(0);
});

it('claims only through the renderer durable cursor', function () {
    $first = createAnalysisTurn($this->capture, $this->salesperson, 'cursor-first', 10);
    $later = createAnalysisTurn($this->capture, $this->customer, 'cursor-later', 20);

    $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/claim",
        ['through_cursor' => 0],
    )->assertOk()
        ->assertJsonCount(0, 'deliveries');

    $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/claim",
        ['through_cursor' => 10],
    )->assertOk()
        ->assertJsonCount(1, 'deliveries')
        ->assertJsonPath('deliveries.0.provider_item_id', 'cursor-first');

    expect($first->fresh()->status)->toBe(AnalysisDeliveryStatus::Processing)
        ->and($later->fresh()->status)->toBe(AnalysisDeliveryStatus::Pending);
});

it('validates the required durable cursor safely', function (array $payload) {
    $this->postJson(
        "/meeting-captures/{$this->capture->id}/analysis-deliveries/claim",
        $payload,
    )->assertUnprocessable();
})->with([
    'missing' => [[]],
    'negative' => [['through_cursor' => -1]],
    'non integer' => [['through_cursor' => 'not-an-integer']],
]);

it('retries SQLite busy claims and leaves one lease owner', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'clueless-claim-');
    $signalPath = "{$databasePath}.locked";
    $attemptPath = "{$databasePath}.attempting";
    $originalConnection = DB::getDefaultConnection();

    expect($databasePath)->toBeString();

    try {
        $sqlite = config('database.connections.sqlite');
        config([
            'database.connections.claim_worker' => [...$sqlite, 'database' => $databasePath],
            'database.connections.claim_holder' => [...$sqlite, 'database' => $databasePath],
        ]);
        DB::purge('claim_worker');
        DB::purge('claim_holder');
        Artisan::call('migrate', ['--database' => 'claim_worker', '--force' => true]);
        DB::setDefaultConnection('claim_worker');

        $conversation = ConversationSession::factory()->ongoing()->create();
        $capture = MeetingCaptureSession::query()->create([
            'conversation_session_id' => $conversation->id,
            'provider' => MeetingProvider::Recall,
            'status' => MeetingCaptureStatus::Active,
            'idempotency_key' => (string) str()->uuid(),
        ]);
        $participant = MeetingParticipant::query()->create([
            'meeting_capture_session_id' => $capture->id,
            'provider_participant_id' => 'contention-customer',
            'display_name' => 'Contention Customer',
            'is_bot' => false,
            'sales_role' => SalesRole::Customer,
        ]);
        $delivery = createAnalysisTurn($capture, $participant, 'contention-turn', 10);

        $pid = pcntl_fork();
        expect($pid)->toBeGreaterThanOrEqual(0);

        if ($pid === 0) {
            DB::purge('claim_holder');
            $holder = DB::connection('claim_holder');
            $holder->statement('PRAGMA busy_timeout = 5000');
            $holder->beginTransaction();
            $holder->table('meeting_analysis_deliveries')
                ->where('id', $delivery->id)
                ->update(['updated_at' => now()]);
            touch($signalPath);
            $deadline = microtime(true) + 2;
            while (! file_exists($attemptPath) && microtime(true) < $deadline) {
                usleep(1000);
            }
            usleep(160000);
            $holder->commit();
            exit(0);
        }

        $deadline = microtime(true) + 2;
        while (! file_exists($signalPath) && microtime(true) < $deadline) {
            usleep(1000);
        }
        expect(file_exists($signalPath))->toBeTrue();

        DB::connection('claim_worker')->statement('PRAGMA busy_timeout = 75');
        touch($attemptPath);
        $claimStartedAt = microtime(true);
        $response = $this->postJson(
            "/meeting-captures/{$capture->id}/analysis-deliveries/claim",
            ['through_cursor' => 10],
        );
        $claimDuration = microtime(true) - $claimStartedAt;
        pcntl_waitpid($pid, $status);

        $response->assertOk()
            ->assertJsonCount(1, 'deliveries')
            ->assertJsonPath('deliveries.0.id', $delivery->id);

        $leaseToken = $response->json('deliveries.0.lease_token');
        expect($claimDuration)->toBeGreaterThan(0.1)
            ->and($leaseToken)->toBeString()
            ->and($delivery->fresh()->status)->toBe(AnalysisDeliveryStatus::Processing)
            ->and($delivery->fresh()->lease_token)->toBe($leaseToken)
            ->and($delivery->fresh()->attempts)->toBe(1)
            ->and($delivery->fresh()->evidence_snapshot)->toBe([
                'current_provider_item_id' => 'contention-turn',
                'prior_provider_item_ids' => [],
            ]);

        $this->postJson(
            "/meeting-captures/{$capture->id}/analysis-deliveries/claim",
            ['through_cursor' => 10],
        )->assertOk()
            ->assertJsonCount(0, 'deliveries');
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('claim_worker');
        DB::purge('claim_holder');

        if (is_string($databasePath) && file_exists($databasePath)) {
            unlink($databasePath);
        }
        if (file_exists($signalPath)) {
            unlink($signalPath);
        }
        if (file_exists($attemptPath)) {
            unlink($attemptPath);
        }
    }
});

function createSnapshotPriorTranscript(
    MeetingCaptureSession $capture,
    MeetingParticipant $participant,
    string $itemId,
    int $offset,
): ConversationTranscript {
    return ConversationTranscript::query()->create([
        'session_id' => $capture->conversation_session_id,
        'speaker' => $participant->sales_role->value,
        'source_stream' => 'recall',
        'text' => "Transcript {$itemId}",
        'spoken_at' => now()->addMilliseconds($offset),
        'status' => 'final',
        'order_index' => $offset,
        'meeting_capture_session_id' => $capture->id,
        'meeting_participant_id' => $participant->id,
        'provider' => MeetingProvider::Recall,
        'provider_item_id' => $itemId,
        'started_offset_ms' => $offset,
        'ended_offset_ms' => $offset + 5,
    ]);
}
