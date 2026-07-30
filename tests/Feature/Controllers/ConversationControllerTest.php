<?php

use App\Enums\AnalysisDeliveryStatus;
use App\Enums\MeetingCaptureStatus;
use App\Enums\MeetingProvider;
use App\Models\ConversationInsight;
use App\Models\ConversationSession;
use App\Models\ConversationTranscript;
use App\Models\MeetingAnalysisDelivery;
use App\Models\MeetingCaptureSession;
use App\Services\ApiKeyService;
use App\Services\ConversationPersistenceService;
use App\Services\MeetingAnalysisDeliveryService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Mock API key service to return true (API key exists) for all conversation tests
    $mockApiKeyService = Mockery::mock(ApiKeyService::class);
    $mockApiKeyService->shouldReceive('hasApiKey')->andReturn(true);
    $this->app->instance(ApiKeyService::class, $mockApiKeyService);

    // Create a test conversation session for some tests
    $this->session = ConversationSession::create([
        'user_id' => null,
        'started_at' => now(),
        'title' => 'Test Conversation',
        'customer_name' => 'John Doe',
        'customer_company' => 'Acme Corp',
    ]);
});

// Index tests
test('can view conversations index page', function () {
    // Create some test sessions
    ConversationSession::factory()->count(5)->create();

    $response = $this->get('/conversations');

    $response->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->component('Conversations/Index')
            ->has('sessions.data', 6) // 5 created + 1 from beforeEach
            ->has('sessions.links')
            ->has('sessions.current_page')
            ->has('sessions.per_page')
            ->has('sessions.total')
        );
});

test('conversations are paginated and ordered by started_at desc', function () {
    // Create sessions with different start times
    ConversationSession::create(['user_id' => null, 'started_at' => now()->subDays(3)]);
    ConversationSession::create(['user_id' => null, 'started_at' => now()->subDays(1)]);
    ConversationSession::create(['user_id' => null, 'started_at' => now()->subDays(2)]);

    $response = $this->get('/conversations');

    $response->assertStatus(200);
    $sessions = $response->original->getData()['page']['props']['sessions']['data'];

    // Check ordering
    expect($sessions[0]['started_at'])->toBeGreaterThan($sessions[1]['started_at']);
    expect($sessions[1]['started_at'])->toBeGreaterThan($sessions[2]['started_at']);
});

// Show tests
test('can view specific conversation session', function () {
    // Add some transcripts and insights
    $this->session->transcripts()->create([
        'speaker' => 'salesperson',
        'text' => 'Hello, how can I help you?',
        'spoken_at' => now(),
        'order_index' => 1,
    ]);

    $this->session->insights()->create([
        'insight_type' => 'key_insight',
        'data' => ['text' => 'Customer interested in product'],
        'captured_at' => now(),
    ]);

    $response = $this->get("/conversations/{$this->session->id}");

    $response->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->component('Conversations/Show')
            ->has('session')
            ->missing('session.transcripts')
            ->missing('session.insights')
            ->has('transcripts.data', 1)
            ->where('transcripts.total', 1)
            ->where('transcripts.data.0.speaker_label', 'You')
            ->where('transcripts.data.0.is_you', false)
            ->has('insights.data', 1)
            ->where('insights.total', 1)
        );
});

test('show bounds and independently paginates ordered conversation data', function () {
    $transcripts = collect(range(1, 205))->map(fn (int $index) => [
        'session_id' => $this->session->id,
        'speaker' => 'salesperson',
        'text' => "Transcript {$index}",
        'spoken_at' => now()->addMilliseconds($index),
        'order_index' => $index,
        'created_at' => now(),
        'updated_at' => now(),
    ])->all();
    ConversationTranscript::insert($transcripts);

    $insights = collect(range(1, 105))->map(fn (int $index) => [
        'session_id' => $this->session->id,
        'insight_type' => 'talk_track',
        'data' => json_encode(['text' => "Insight {$index}"]),
        'captured_at' => now()->addMilliseconds($index),
        'created_at' => now(),
        'updated_at' => now(),
    ])->all();
    \App\Models\ConversationInsight::insert($insights);

    $response = $this->get("/conversations/{$this->session->id}");
    $props = $response->original->getData()['page']['props'];

    expect($props['transcripts']['data'])->toHaveCount(200)
        ->and($props['transcripts']['total'])->toBe(205)
        ->and($props['transcripts']['data'][0]['order_index'])->toBe(1)
        ->and($props['insights']['data'])->toHaveCount(100)
        ->and($props['insights']['total'])->toBe(105);
});

test('show returns 404 for non-existent session', function () {
    $response = $this->get('/conversations/999999');

    $response->assertStatus(404);
});

// Store tests
test('can start new conversation session', function () {
    $response = $this->postJson('/conversations', [
        'template_used' => 'sales_call',
        'customer_name' => 'Jane Smith',
        'customer_company' => 'Tech Corp',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'session_id',
            'message',
        ])
        ->assertJson([
            'message' => 'Session started successfully',
        ]);

    $this->assertDatabaseHas('conversation_sessions', [
        'template_used' => 'sales_call',
        'customer_name' => 'Jane Smith',
        'customer_company' => 'Tech Corp',
        'user_id' => null,
    ]);
});

test('can start conversation session without optional fields', function () {
    $response = $this->postJson('/conversations');

    $response->assertStatus(200);

    $this->assertDatabaseHas('conversation_sessions', [
        'user_id' => null,
        'template_used' => null,
        'customer_name' => null,
        'customer_company' => null,
    ]);
});

// End session tests
test('can end conversation session with metrics', function () {
    // Add some insights to test counting
    $this->session->insights()->createMany([
        ['insight_type' => 'key_insight', 'data' => ['text' => 'Test'], 'captured_at' => now()],
        ['insight_type' => 'topic', 'data' => ['text' => 'Test'], 'captured_at' => now()],
        ['insight_type' => 'commitment', 'data' => ['text' => 'Test'], 'captured_at' => now()],
        ['insight_type' => 'action_item', 'data' => ['text' => 'Test'], 'captured_at' => now()],
    ]);

    $this->session->transcripts()->create([
        'speaker' => 'salesperson',
        'text' => 'Test transcript',
        'spoken_at' => now(),
        'order_index' => 1,
    ]);

    $response = $this->postJson("/conversations/{$this->session->id}/end", [
        'duration_seconds' => 300,
        'final_intent' => 'high',
        'final_buying_stage' => 'evaluation',
        'final_engagement_level' => 80,
        'final_sentiment' => 'positive',
        'ai_summary' => 'Great conversation with customer.',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Session ended successfully',
        ]);

    $this->session->refresh();

    expect($this->session->ended_at)->not->toBeNull();
    expect($this->session->duration_seconds)->toBe(300);
    expect($this->session->final_intent)->toBe('high');
    expect($this->session->final_buying_stage)->toBe('evaluation');
    expect($this->session->final_engagement_level)->toBe(80);
    expect($this->session->final_sentiment)->toBe('positive');
    expect($this->session->ai_summary)->toBe('Great conversation with customer.');
    expect($this->session->total_transcripts)->toBe(1);
    expect($this->session->total_insights)->toBe(1);
    expect($this->session->total_topics)->toBe(1);
    expect($this->session->total_commitments)->toBe(1);
    expect($this->session->total_action_items)->toBe(1);
});

test('end session counts modern card types', function () {
    $this->session->insights()->createMany([
        ['insight_type' => 'knowledge_card', 'card_type' => 'knowledge_card', 'data' => ['text' => 'Knowledge'], 'captured_at' => now()],
        ['insight_type' => 'talk_track', 'card_type' => 'talk_track', 'data' => ['text' => 'Talk track'], 'captured_at' => now()],
        ['insight_type' => 'objection', 'card_type' => 'objection', 'data' => ['text' => 'Objection'], 'captured_at' => now()],
        ['insight_type' => 'topic', 'card_type' => 'topic', 'data' => ['text' => 'Pricing'], 'captured_at' => now()],
        ['insight_type' => 'commitment', 'card_type' => 'commitment', 'data' => ['text' => 'Commitment'], 'captured_at' => now()],
        ['insight_type' => 'action_item', 'card_type' => 'action_item', 'data' => ['text' => 'Follow up'], 'captured_at' => now()],
        ['insight_type' => 'customer_intelligence', 'card_type' => 'customer_intelligence', 'data' => ['intent' => 'evaluation'], 'captured_at' => now()],
    ]);

    $this->postJson("/conversations/{$this->session->id}/end", [
        'duration_seconds' => 60,
    ])->assertOk();

    $this->session->refresh();

    expect($this->session->total_insights)->toBe(4)
        ->and($this->session->total_topics)->toBe(1)
        ->and($this->session->total_commitments)->toBe(1)
        ->and($this->session->total_action_items)->toBe(1);
});

it('persists local cards into history and counts local discussion topics without Recall evidence metadata', function () {
    $capturedAt = now()->timestamp * 1000;

    $this->postJson("/conversations/{$this->session->id}/insights", [
        'insights' => [
            [
                'insight_type' => 'pain_point',
                'tool_call_id' => 'local-pain-call',
                'card_type' => 'local_pain_point',
                'data' => [
                    'text' => 'Manual handoffs slow the sales team down.',
                    'category' => 'Operations',
                    'severity' => 'high',
                ],
                'captured_at' => $capturedAt,
            ],
            [
                'insight_type' => 'discussion_topic',
                'tool_call_id' => 'local-topic-call',
                'card_type' => 'local_discussion_topic',
                'data' => [
                    'name' => 'Implementation timeline',
                    'sentiment' => 'mixed',
                    'context' => 'The customer needs a phased rollout.',
                ],
                'captured_at' => $capturedAt + 1,
            ],
        ],
    ])->assertOk();

    $this->postJson("/conversations/{$this->session->id}/end", [
        'duration_seconds' => 180,
    ])->assertOk();

    $response = $this->get("/conversations/{$this->session->id}")->assertOk();
    $insights = collect($response->original->getData()['page']['props']['insights']['data'])
        ->keyBy('card_type');

    expect($this->session->fresh()->total_topics)->toBe(1)
        ->and($insights)->toHaveKeys(['local_pain_point', 'local_discussion_topic'])
        ->and($insights['local_pain_point']['data']['text'])
        ->toBe('Manual handoffs slow the sales team down.')
        ->and($insights['local_pain_point']['analysis_delivery_id'])->toBeNull()
        ->and($insights['local_pain_point']['evidence_item_ids'])->toBe([])
        ->and($insights['local_discussion_topic']['data']['name'])
        ->toBe('Implementation timeline')
        ->and($insights['local_discussion_topic']['analysis_delivery_id'])->toBeNull()
        ->and($insights['local_discussion_topic']['evidence_item_ids'])->toBe([]);
});

test('end session requires duration_seconds', function () {
    $response = $this->postJson("/conversations/{$this->session->id}/end", [
        'final_intent' => 'high',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['duration_seconds']);
});

it('does not erase terminal details on repeated end', function () {
    $this->postJson("/conversations/{$this->session->id}/end", [
        'duration_seconds' => 420,
        'final_intent' => 'high',
        'final_buying_stage' => 'decision',
        'final_engagement_level' => 92,
        'final_sentiment' => 'positive',
        'ai_summary' => 'The customer approved the technical evaluation.',
    ])->assertOk();

    $endedAt = $this->session->fresh()->ended_at;

    $this->travel(5)->seconds();
    $this->postJson("/conversations/{$this->session->id}/end", [
        'duration_seconds' => 0,
    ])->assertOk();

    $session = $this->session->fresh();

    expect($session->ended_at?->equalTo($endedAt))->toBeTrue()
        ->and($session->duration_seconds)->toBe(420)
        ->and($session->final_intent)->toBe('high')
        ->and($session->final_buying_stage)->toBe('decision')
        ->and($session->final_engagement_level)->toBe(92)
        ->and($session->final_sentiment)->toBe('positive')
        ->and($session->ai_summary)->toBe('The customer approved the technical evaluation.');
});

// Save transcript tests
test('can save single transcript', function () {
    $response = $this->postJson("/conversations/{$this->session->id}/transcript", [
        'speaker' => 'customer',
        'text' => 'I need help with your product',
        'spoken_at' => now()->timestamp * 1000, // milliseconds
        'group_id' => 'group-123',
        'system_category' => 'greeting',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'transcript_id',
            'message',
        ])
        ->assertJson([
            'message' => 'Transcript saved successfully',
        ]);

    $this->assertDatabaseHas('conversation_transcripts', [
        'session_id' => $this->session->id,
        'speaker' => 'customer',
        'text' => 'I need help with your product',
        'group_id' => 'group-123',
        'system_category' => 'greeting',
        'order_index' => 1,
    ]);
});

test('transcript order_index increments correctly', function () {
    // Create existing transcripts
    $this->session->transcripts()->createMany([
        ['speaker' => 'salesperson', 'text' => 'First', 'spoken_at' => now(), 'order_index' => 1],
        ['speaker' => 'customer', 'text' => 'Second', 'spoken_at' => now(), 'order_index' => 2],
    ]);

    $response = $this->postJson("/conversations/{$this->session->id}/transcript", [
        'speaker' => 'salesperson',
        'text' => 'Third transcript',
        'spoken_at' => now()->timestamp * 1000,
    ]);

    $response->assertStatus(200);

    $transcript = ConversationTranscript::where('text', 'Third transcript')->first();
    expect($transcript->order_index)->toBe(3);
});

test('single transcript persistence is idempotent by stream and OpenAI item', function () {
    $payload = [
        'speaker' => 'customer',
        'source_stream' => 'customer_audio',
        'text' => 'Partial words',
        'spoken_at' => now()->timestamp * 1000,
        'openai_item_id' => 'item_customer_1',
        'status' => 'partial',
    ];

    $first = $this->postJson("/conversations/{$this->session->id}/transcript", $payload)
        ->assertOk();

    $second = $this->postJson("/conversations/{$this->session->id}/transcript", [
        ...$payload,
        'text' => 'Final words',
        'status' => 'final',
    ])->assertOk();

    expect($second->json('transcript_id'))->toBe($first->json('transcript_id'))
        ->and($this->session->transcripts()->count())->toBe(1);

    $transcript = $this->session->transcripts()->first();

    expect($transcript->text)->toBe('Final words')
        ->and($transcript->status)->toBe('final')
        ->and($transcript->order_index)->toBe(1);
});

test('save transcript validates speaker values', function () {
    $response = $this->postJson("/conversations/{$this->session->id}/transcript", [
        'speaker' => 'invalid',
        'text' => 'Test',
        'spoken_at' => now()->timestamp * 1000,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['speaker']);
});

it('keeps Recall webhook persistence separate from the local transcript queue', function () {
    $this->postJson("/conversations/{$this->session->id}/transcript", [
        'speaker' => 'customer',
        'source_stream' => 'recall',
        'text' => 'A renderer must not manufacture a Recall turn.',
        'spoken_at' => now()->timestamp * 1000,
        'provider' => 'recall',
        'provider_item_id' => 'forged-provider-item',
        'meeting_capture_session_id' => (string) str()->uuid(),
        'meeting_participant_id' => 99,
        'started_offset_ms' => 100,
        'ended_offset_ms' => 200,
        'order_index' => 999,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([
            'provider',
            'provider_item_id',
            'meeting_capture_session_id',
            'meeting_participant_id',
            'started_offset_ms',
            'ended_offset_ms',
            'order_index',
        ]);

    expect($this->session->transcripts()->count())->toBe(0);
});

// Save batch transcripts tests
test('can save batch transcripts', function () {
    $transcripts = [
        [
            'speaker' => 'salesperson',
            'text' => 'Hello',
            'spoken_at' => now()->timestamp * 1000,
            'group_id' => 'group-1',
        ],
        [
            'speaker' => 'customer',
            'text' => 'Hi there',
            'spoken_at' => now()->addSeconds(1)->timestamp * 1000,
            'group_id' => 'group-1',
        ],
        [
            'speaker' => 'salesperson',
            'text' => 'How can I help?',
            'spoken_at' => now()->addSeconds(2)->timestamp * 1000,
            'group_id' => 'group-2',
        ],
    ];

    $response = $this->postJson("/conversations/{$this->session->id}/transcripts", [
        'transcripts' => $transcripts,
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Transcripts saved successfully',
        ]);

    expect($this->session->transcripts()->count())->toBe(3);

    // Check order indexes
    $savedTranscripts = $this->session->transcripts()->orderBy('order_index')->get();
    expect($savedTranscripts[0]->order_index)->toBe(1);
    expect($savedTranscripts[1]->order_index)->toBe(2);
    expect($savedTranscripts[2]->order_index)->toBe(3);
});

test('batch transcripts use upsert semantics on replay', function () {
    $payload = [
        'transcripts' => [
            [
                'speaker' => 'salesperson',
                'source_stream' => 'salesperson_audio',
                'text' => 'First partial',
                'spoken_at' => now()->timestamp * 1000,
                'openai_item_id' => 'item_sales_1',
                'status' => 'partial',
            ],
            [
                'speaker' => 'customer',
                'source_stream' => 'customer_audio',
                'text' => 'Second final',
                'spoken_at' => now()->addSecond()->timestamp * 1000,
                'openai_item_id' => 'item_customer_2',
                'status' => 'final',
            ],
        ],
    ];

    $this->postJson("/conversations/{$this->session->id}/transcripts", $payload)->assertOk();

    $payload['transcripts'][0]['text'] = 'First final';
    $payload['transcripts'][0]['status'] = 'final';

    $this->postJson("/conversations/{$this->session->id}/transcripts", $payload)->assertOk();

    expect($this->session->transcripts()->count())->toBe(2)
        ->and($this->session->transcripts()->where('openai_item_id', 'item_sales_1')->value('text'))
        ->toBe('First final');
});

test('batch transcripts validates each transcript', function () {
    $transcripts = [
        [
            'speaker' => 'salesperson',
            'text' => 'Valid',
            'spoken_at' => now()->timestamp * 1000,
        ],
        [
            'speaker' => 'invalid_speaker',
            'text' => 'Invalid',
            'spoken_at' => now()->timestamp * 1000,
        ],
    ];

    $response = $this->postJson("/conversations/{$this->session->id}/transcripts", [
        'transcripts' => $transcripts,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['transcripts.1.speaker']);

    expect($this->session->transcripts()->count())->toBe(0);
});

test('transcript persistence enforces batch and text limits', function () {
    $transcript = [
        'speaker' => 'salesperson',
        'text' => 'Valid',
        'spoken_at' => now()->timestamp * 1000,
    ];

    $this->postJson("/conversations/{$this->session->id}/transcripts", [
        'transcripts' => array_fill(0, 501, $transcript),
    ])->assertJsonValidationErrors(['transcripts']);

    $this->postJson("/conversations/{$this->session->id}/transcript", [
        ...$transcript,
        'text' => str_repeat('x', 50_001),
    ])->assertJsonValidationErrors(['text']);
});

// Save insight tests
test('can save single insight', function () {
    $response = $this->postJson("/conversations/{$this->session->id}/insight", [
        'insight_type' => 'key_insight',
        'data' => [
            'text' => 'Customer is very interested',
            'importance' => 'high',
        ],
        'captured_at' => now()->timestamp * 1000,
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'insight_id',
            'message',
        ])
        ->assertJson([
            'message' => 'Insight saved successfully',
        ]);

    $this->assertDatabaseHas('conversation_insights', [
        'session_id' => $this->session->id,
        'insight_type' => 'key_insight',
    ]);
});

test('single insight persistence is idempotent by tool call', function () {
    $payload = [
        'insight_type' => 'talk_track',
        'tool_call_id' => 'call_talk_track_1',
        'card_type' => 'talk_track',
        'data' => ['text' => 'Initial suggestion'],
        'captured_at' => now()->timestamp * 1000,
    ];

    $first = $this->postJson("/conversations/{$this->session->id}/insight", $payload)
        ->assertOk();

    $second = $this->postJson("/conversations/{$this->session->id}/insight", [
        ...$payload,
        'data' => ['text' => 'Revised suggestion'],
    ])->assertOk();

    expect($second->json('insight_id'))->toBe($first->json('insight_id'))
        ->and($this->session->insights()->count())->toBe(1)
        ->and($this->session->insights()->first()->data['text'])->toBe('Revised suggestion');
});

// Save batch insights tests
test('can save batch insights', function () {
    $insights = [
        [
            'insight_type' => 'topic',
            'data' => ['text' => 'Product pricing', 'importance' => 'high'],
            'captured_at' => now()->timestamp * 1000,
        ],
        [
            'insight_type' => 'commitment',
            'data' => ['text' => 'Schedule follow-up', 'importance' => 'medium'],
            'captured_at' => now()->addSeconds(1)->timestamp * 1000,
        ],
        [
            'insight_type' => 'action_item',
            'data' => ['text' => 'Send proposal', 'importance' => 'high'],
            'captured_at' => now()->addSeconds(2)->timestamp * 1000,
        ],
    ];

    $response = $this->postJson("/conversations/{$this->session->id}/insights", [
        'insights' => $insights,
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Insights saved successfully',
        ]);

    expect($this->session->insights()->count())->toBe(3);
    expect($this->session->insights()->where('insight_type', 'topic')->count())->toBe(1);
    expect($this->session->insights()->where('insight_type', 'commitment')->count())->toBe(1);
    expect($this->session->insights()->where('insight_type', 'action_item')->count())->toBe(1);
});

test('batch insights use upsert semantics on replay', function () {
    $payload = [
        'insights' => [
            [
                'insight_type' => 'knowledge_card',
                'tool_call_id' => 'call_knowledge_1',
                'card_type' => 'knowledge_card',
                'data' => ['title' => 'Initial'],
                'captured_at' => now()->timestamp * 1000,
            ],
            [
                'insight_type' => 'commitment',
                'tool_call_id' => 'call_commitment_1',
                'card_type' => 'commitment',
                'data' => ['text' => 'Send pricing'],
                'captured_at' => now()->addSecond()->timestamp * 1000,
            ],
        ],
    ];

    $this->postJson("/conversations/{$this->session->id}/insights", $payload)->assertOk();

    $payload['insights'][0]['data']['title'] = 'Updated';
    $this->postJson("/conversations/{$this->session->id}/insights", $payload)->assertOk();

    expect($this->session->insights()->count())->toBe(2)
        ->and($this->session->insights()->where('tool_call_id', 'call_knowledge_1')->first()->data['title'])
        ->toBe('Updated');
});

test('insight persistence enforces batch and data size limits', function () {
    $insight = [
        'insight_type' => 'talk_track',
        'data' => ['text' => 'Valid'],
        'captured_at' => now()->timestamp * 1000,
    ];

    $this->postJson("/conversations/{$this->session->id}/insights", [
        'insights' => array_fill(0, 201, $insight),
    ])->assertJsonValidationErrors(['insights']);

    $this->postJson("/conversations/{$this->session->id}/insight", [
        ...$insight,
        'data' => ['text' => str_repeat('x', 65_537)],
    ])->assertJsonValidationErrors(['data']);
});

it('accepts same-capture finalized Recall evidence', function () {
    $capture = createInsightEvidenceCapture($this->session);
    createInsightEvidenceTranscript($capture, 'recall-prior');
    $deliveryTranscript = createInsightEvidenceTranscript($capture, 'recall-current');
    $delivery = createInsightEvidenceDelivery($capture, $deliveryTranscript);

    $response = $this->postJson("/conversations/{$this->session->id}/insight", [
        'insight_type' => 'pain_point',
        'tool_call_id' => 'call-pain-valid',
        'card_type' => 'pain_point',
        'analysis_delivery_id' => $delivery->id,
        'evidence_item_ids' => ['recall-current', 'recall-prior'],
        'semantic_key' => 'renderer-must-not-control-this',
        'data' => [
            'text' => '  Manual reconciliation takes too long.  ',
            'category' => '  Operations  ',
            'severity' => 'high',
        ],
        'captured_at' => now()->timestamp * 1000,
    ])->assertOk();

    $insight = ConversationInsight::query()->findOrFail($response->json('insight_id'));

    expect($insight->semantic_key)
        ->toBeString()
        ->not->toBe('renderer-must-not-control-this')
        ->and($insight->data)->toBe([
            'text' => 'Manual reconciliation takes too long.',
            'category' => 'Operations',
            'severity' => 'high',
        ])
        ->and($insight->metadata['analysis_delivery_id'])->toBe($delivery->id)
        ->and($insight->metadata['evidence_item_ids'])->toBe([
            'recall-current',
            'recall-prior',
        ]);
});

it('rejects foreign partial local cross-capture and missing evidence', function (
    string $case,
) {
    $capture = createInsightEvidenceCapture($this->session);
    $deliveryTranscript = createInsightEvidenceTranscript($capture, 'recall-current');
    $delivery = createInsightEvidenceDelivery($capture, $deliveryTranscript);

    $evidenceId = match ($case) {
        'partial' => createInsightEvidenceTranscript(
            $capture,
            'partial-item',
            1,
            'partial',
        )->provider_item_id,
        'local' => createInsightEvidenceTranscript(
            $capture,
            'local-item',
            1,
            'final',
            MeetingProvider::Local,
        )->provider_item_id,
        'local-capture' => createInsightEvidenceTranscript(
            createInsightEvidenceCapture($this->session, MeetingProvider::Local),
            'local-capture-item',
        )->provider_item_id,
        'cross-capture' => createInsightEvidenceTranscript(
            createInsightEvidenceCapture($this->session),
            'cross-capture-item',
        )->provider_item_id,
        'foreign' => createInsightEvidenceTranscript(
            createInsightEvidenceCapture(ConversationSession::factory()->ongoing()->create()),
            'foreign-item',
        )->provider_item_id,
        default => 'missing-item',
    };

    $this->postJson("/conversations/{$this->session->id}/insight", [
        'insight_type' => 'discussion_topic',
        'tool_call_id' => "call-topic-{$case}",
        'card_type' => 'discussion_topic',
        'analysis_delivery_id' => $delivery->id,
        'evidence_item_ids' => [$evidenceId],
        'data' => [
            'name' => 'Implementation',
            'sentiment' => 'mixed',
            'context' => 'The customer is concerned about the rollout.',
        ],
        'captured_at' => now()->timestamp * 1000,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['evidence_item_ids']);

    expect($this->session->insights()->count())->toBe(0);
})->with(['foreign', 'partial', 'local', 'local-capture', 'cross-capture', 'missing']);

it('rejects evidence tied to a delivery from another conversation', function () {
    $foreignSession = ConversationSession::factory()->ongoing()->create();
    $foreignCapture = createInsightEvidenceCapture($foreignSession);
    $foreignTranscript = createInsightEvidenceTranscript($foreignCapture, 'foreign-current');
    $foreignDelivery = createInsightEvidenceDelivery($foreignCapture, $foreignTranscript);

    $this->postJson("/conversations/{$this->session->id}/insight", [
        'insight_type' => 'pain_point',
        'tool_call_id' => 'call-foreign-delivery',
        'card_type' => 'pain_point',
        'analysis_delivery_id' => $foreignDelivery->id,
        'evidence_item_ids' => ['foreign-current'],
        'data' => [
            'text' => 'Foreign evidence',
            'category' => null,
            'severity' => 'low',
        ],
        'captured_at' => now()->timestamp * 1000,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['analysis_delivery_id']);
});

it('rejects evidence tied to a local capture delivery', function () {
    $capture = createInsightEvidenceCapture($this->session, MeetingProvider::Local);
    $transcript = createInsightEvidenceTranscript($capture, 'local-delivery-item');
    $delivery = createInsightEvidenceDelivery($capture, $transcript);

    $this->postJson("/conversations/{$this->session->id}/insight", [
        'insight_type' => 'pain_point',
        'tool_call_id' => 'call-local-delivery',
        'card_type' => 'pain_point',
        'analysis_delivery_id' => $delivery->id,
        'evidence_item_ids' => ['local-delivery-item'],
        'data' => [
            'text' => 'Local capture evidence',
            'category' => null,
            'severity' => 'low',
        ],
        'captured_at' => now()->timestamp * 1000,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['analysis_delivery_id']);
});

it('deduplicates replay with a new tool call id by semantic key', function () {
    $capture = createInsightEvidenceCapture($this->session);
    createInsightEvidenceTranscript($capture, 'topic-prior');
    $deliveryTranscript = createInsightEvidenceTranscript($capture, 'topic-current');
    $delivery = createInsightEvidenceDelivery($capture, $deliveryTranscript);

    $base = [
        'insight_type' => 'topic',
        'card_type' => 'discussion_topic',
        'analysis_delivery_id' => $delivery->id,
        'evidence_item_ids' => ['topic-current', 'topic-prior'],
        'data' => [
            'name' => '  Implementation Timeline  ',
            'sentiment' => 'mixed',
            'context' => '  Customer needs a phased rollout.  ',
        ],
        'captured_at' => now()->timestamp * 1000,
    ];

    $first = $this->postJson("/conversations/{$this->session->id}/insight", [
        ...$base,
        'tool_call_id' => 'call-topic-first',
    ])->assertOk();

    $second = $this->postJson("/conversations/{$this->session->id}/insight", [
        ...$base,
        'tool_call_id' => 'call-topic-replay',
        'evidence_item_ids' => ['topic-prior', 'topic-current', 'topic-prior'],
        'data' => [
            'name' => 'implementation timeline',
            'sentiment' => 'mixed',
            'context' => 'Customer needs a phased rollout.',
        ],
    ])->assertOk();

    expect($second->json('insight_id'))->toBe($first->json('insight_id'))
        ->and($this->session->insights()->count())->toBe(1)
        ->and($this->session->insights()->first()->tool_call_id)->toBe('call-topic-first');
});

it('rejects future and too-old evidence outside the delivery analysis window', function (
    string $invalidEvidenceId,
) {
    $capture = createInsightEvidenceCapture($this->session);

    foreach (range(0, 9) as $index) {
        createInsightEvidenceTranscript($capture, "prior-{$index}", $index);
    }

    $current = createInsightEvidenceTranscript($capture, 'current', 10);
    $delivery = createInsightEvidenceDelivery($capture, $current);
    createInsightEvidenceTranscript($capture, 'future', 11);

    $this->postJson("/conversations/{$this->session->id}/insight", [
        'insight_type' => 'discussion_topic',
        'tool_call_id' => "call-window-{$invalidEvidenceId}",
        'card_type' => 'discussion_topic',
        'analysis_delivery_id' => $delivery->id,
        'evidence_item_ids' => ['current', 'prior-9', $invalidEvidenceId],
        'data' => [
            'name' => 'Implementation',
            'sentiment' => 'mixed',
            'context' => 'Evidence must stay inside the supplied analysis window.',
        ],
        'captured_at' => now()->timestamp * 1000,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['evidence_item_ids']);
})->with([
    'future' => 'future',
    'too old' => 'prior-1',
]);

it('requires strict evidence card payloads', function (array $override, string $errorKey) {
    $capture = createInsightEvidenceCapture($this->session);
    $transcript = createInsightEvidenceTranscript($capture, 'strict-current');
    $delivery = createInsightEvidenceDelivery($capture, $transcript);
    $payload = [
        'insight_type' => 'pain_point',
        'tool_call_id' => 'call-strict-pain',
        'card_type' => 'pain_point',
        'analysis_delivery_id' => $delivery->id,
        'evidence_item_ids' => ['strict-current'],
        'data' => [
            'text' => 'Manual work',
            'category' => null,
            'severity' => 'medium',
        ],
        'captured_at' => now()->timestamp * 1000,
    ];

    $this->postJson(
        "/conversations/{$this->session->id}/insight",
        array_replace(
            $payload,
            $override,
            isset($override['data'])
                ? ['data' => array_replace($payload['data'], $override['data'])]
                : [],
        ),
    )->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey]);
})->with([
    'missing tool call' => [['tool_call_id' => null], 'tool_call_id'],
    'blank tool call' => [['tool_call_id' => '   '], 'tool_call_id'],
    'missing delivery' => [['analysis_delivery_id' => null], 'analysis_delivery_id'],
    'empty evidence' => [['evidence_item_ids' => []], 'evidence_item_ids'],
    'blank evidence id' => [['evidence_item_ids' => ['   ']], 'evidence_item_ids.0'],
    'blank pain text' => [['data' => ['text' => '   ']], 'data.text'],
    'invalid severity' => [['data' => ['severity' => 'urgent']], 'data.severity'],
    'extra pain data' => [['data' => ['unexpected' => true]], 'data'],
]);

it('keeps semantic and tool-call idempotency safe under real SQLite contention', function (
    string $mode,
) {
    $databasePath = tempnam(sys_get_temp_dir(), 'clueless-insight-race-');
    $signalPath = "{$databasePath}.inserted";
    $resultPath = "{$databasePath}.result";
    $originalConnection = DB::getDefaultConnection();

    expect($databasePath)->toBeString();

    try {
        $sqlite = config('database.connections.sqlite');
        config([
            'database.connections.insight_parent' => [
                ...$sqlite,
                'database' => $databasePath,
            ],
            'database.connections.insight_holder' => [
                ...$sqlite,
                'database' => $databasePath,
            ],
        ]);
        DB::purge('insight_parent');
        DB::purge('insight_holder');
        Artisan::call('migrate', ['--database' => 'insight_parent', '--force' => true]);
        DB::setDefaultConnection('insight_parent');

        $session = ConversationSession::factory()->ongoing()->create();
        $capture = createInsightEvidenceCapture($session);
        $transcript = createInsightEvidenceTranscript($capture, 'race-current');
        $delivery = createInsightEvidenceDelivery($capture, $transcript);
        $base = $mode === 'semantic'
            ? [
                'insight_type' => 'pain_point',
                'card_type' => 'pain_point',
                'analysis_delivery_id' => $delivery->id,
                'evidence_item_ids' => ['race-current'],
                'data' => [
                    'text' => 'Manual reconciliation',
                    'category' => 'Operations',
                    'severity' => 'high',
                ],
                'captured_at' => now()->timestamp * 1000,
            ]
            : [
                'insight_type' => 'talk_track',
                'card_type' => 'talk_track',
                'data' => ['text' => 'Discuss rollout'],
                'captured_at' => now()->timestamp * 1000,
            ];
        $holderPayload = [
            ...$base,
            'tool_call_id' => $mode === 'semantic' ? 'call-race-holder' : 'call-race-shared',
        ];
        $parentPayload = [
            ...$base,
            'tool_call_id' => $mode === 'semantic' ? 'call-race-parent' : 'call-race-shared',
        ];

        $pid = pcntl_fork();
        expect($pid)->toBeGreaterThanOrEqual(0);

        if ($pid === 0) {
            DB::purge('insight_holder');
            DB::setDefaultConnection('insight_holder');
            $holder = DB::connection('insight_holder');
            $holder->statement('PRAGMA busy_timeout = 5000');

            try {
                $holder->beginTransaction();
                app(ConversationPersistenceService::class)->persistInsight(
                    ConversationSession::query()->findOrFail($session->id),
                    $holderPayload,
                );
                touch($signalPath);
                usleep(150000);
                $holder->commit();
                file_put_contents($resultPath, 'ok');
                exit(0);
            } catch (Throwable $exception) {
                if ($holder->transactionLevel() > 0) {
                    $holder->rollBack();
                }

                file_put_contents($resultPath, $exception::class.': '.$exception->getMessage());
                exit(1);
            }
        }

        $deadline = microtime(true) + 2;
        while (! file_exists($signalPath) && microtime(true) < $deadline) {
            usleep(1000);
        }
        expect(file_exists($signalPath))->toBeTrue();

        DB::connection('insight_parent')->statement('PRAGMA busy_timeout = 50');
        $persisted = app(ConversationPersistenceService::class)->persistInsight(
            $session->fresh(),
            $parentPayload,
        );

        pcntl_waitpid($pid, $status);

        expect(pcntl_wifexited($status))->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0)
            ->and(file_get_contents($resultPath))->toBe('ok')
            ->and(ConversationInsight::query()->count())->toBe(1)
            ->and($persisted->id)->toBe(ConversationInsight::query()->value('id'));
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('insight_parent');
        DB::purge('insight_holder');
        @unlink($signalPath);
        @unlink($resultPath);
        @unlink($databasePath);
    }
})->with(['semantic', 'tool_call']);

function createInsightEvidenceCapture(
    ConversationSession $session,
    MeetingProvider $provider = MeetingProvider::Recall,
): MeetingCaptureSession {
    return MeetingCaptureSession::query()->create([
        'conversation_session_id' => $session->id,
        'provider' => $provider,
        'status' => MeetingCaptureStatus::Active,
        'idempotency_key' => (string) str()->uuid(),
    ]);
}

function createInsightEvidenceTranscript(
    MeetingCaptureSession $capture,
    string $providerItemId,
    int $orderOffset = 0,
    string $status = 'final',
    MeetingProvider $provider = MeetingProvider::Recall,
): ConversationTranscript {
    $orderIndex = ((int) ConversationTranscript::query()
        ->where('session_id', $capture->conversation_session_id)
        ->max('order_index')) + 1;

    return ConversationTranscript::query()->create([
        'session_id' => $capture->conversation_session_id,
        'speaker' => 'customer',
        'source_stream' => $provider->value,
        'text' => "Evidence {$providerItemId}",
        'spoken_at' => now()->addMilliseconds($orderOffset),
        'status' => $status,
        'order_index' => $orderIndex,
        'meeting_capture_session_id' => $capture->id,
        'provider' => $provider,
        'provider_item_id' => $providerItemId,
    ]);
}

function createInsightEvidenceDelivery(
    MeetingCaptureSession $capture,
    ConversationTranscript $transcript,
): MeetingAnalysisDelivery {
    $delivery = MeetingAnalysisDelivery::query()->create([
        'meeting_capture_session_id' => $capture->id,
        'conversation_transcript_id' => $transcript->id,
        'status' => AnalysisDeliveryStatus::Pending,
    ]);

    app(MeetingAnalysisDeliveryService::class)->claim(
        $capture,
        (int) ConversationTranscript::query()
            ->where('meeting_capture_session_id', $capture->id)
            ->max('order_index'),
    );

    return $delivery->fresh();
}
// Update notes tests
test('can update session notes', function () {
    $response = $this->patchJson("/conversations/{$this->session->id}/notes", [
        'user_notes' => 'Customer seems very interested in enterprise features.',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Notes updated successfully',
        ]);

    $this->session->refresh();
    expect($this->session->user_notes)->toBe('Customer seems very interested in enterprise features.');
});

test('can clear session notes', function () {
    $this->session->update(['user_notes' => 'Some existing notes']);

    $response = $this->patchJson("/conversations/{$this->session->id}/notes", [
        'user_notes' => null,
    ]);

    $response->assertStatus(200);

    $this->session->refresh();
    expect($this->session->user_notes)->toBeNull();
});

// Update title tests
test('can update session title', function () {
    $response = $this->patchJson("/conversations/{$this->session->id}/title", [
        'title' => 'Important Sales Call with Acme Corp',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Title updated successfully',
        ]);

    $this->session->refresh();
    expect($this->session->title)->toBe('Important Sales Call with Acme Corp');
});

test('update title validates required field', function () {
    $response = $this->patchJson("/conversations/{$this->session->id}/title", []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['title']);
});

test('update title validates max length', function () {
    $response = $this->patchJson("/conversations/{$this->session->id}/title", [
        'title' => str_repeat('a', 256),
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['title']);
});

// Delete tests
test('can delete conversation session', function () {
    $sessionId = $this->session->id;

    $response = $this->delete("/conversations/{$sessionId}");

    $response->assertRedirect('/conversations')
        ->assertSessionHas('message', 'Conversation deleted successfully');

    $this->assertDatabaseMissing('conversation_sessions', ['id' => $sessionId]);
});

test('delete removes related transcripts and insights', function () {
    // Create related data
    $this->session->transcripts()->create([
        'speaker' => 'salesperson',
        'text' => 'Test',
        'spoken_at' => now(),
        'order_index' => 1,
    ]);

    $this->session->insights()->create([
        'insight_type' => 'topic',
        'data' => ['text' => 'Test'],
        'captured_at' => now(),
    ]);

    $sessionId = $this->session->id;

    $response = $this->delete("/conversations/{$sessionId}");

    $response->assertRedirect('/conversations');

    // Check cascade deletion
    $this->assertDatabaseMissing('conversation_transcripts', ['session_id' => $sessionId]);
    $this->assertDatabaseMissing('conversation_insights', ['session_id' => $sessionId]);
});
