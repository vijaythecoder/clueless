<?php

use App\Models\ConversationSession;
use App\Models\ConversationTranscript;
use App\Models\ConversationInsight;
use Tests\Traits\MocksOnboarding;

uses(MocksOnboarding::class);

beforeEach(function () {
    // Mock complete onboarding flow (API key + permissions + completion) for all conversation tests
    $this->mockCompletedOnboarding();
    
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
            ->has('transcripts', 1)
            ->has('insights')
        );
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

test('end session requires duration_seconds', function () {
    $response = $this->postJson("/conversations/{$this->session->id}/end", [
        'final_intent' => 'high',
    ]);
    
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['duration_seconds']);
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

test('save transcript validates speaker values', function () {
    $response = $this->postJson("/conversations/{$this->session->id}/transcript", [
        'speaker' => 'invalid',
        'text' => 'Test',
        'spoken_at' => now()->timestamp * 1000,
    ]);
    
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['speaker']);
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