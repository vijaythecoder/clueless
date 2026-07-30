<?php

use App\Enums\MeetingCaptureStatus;
use App\Enums\MeetingProvider;
use App\Enums\SalesRole;
use App\Exceptions\MissingOpenAIKeyException;
use App\Exceptions\OpenAIResponsesException;
use App\Models\ConversationSession;
use App\Models\ConversationTranscript;
use App\Models\MeetingAnalysisDelivery;
use App\Models\MeetingCaptureSession;
use App\Models\MeetingParticipant;
use App\Services\ApiKeyService;
use App\Services\CopilotSessionService;
use App\Services\MeetingAnalysisContextService;
use App\Services\OpenAIResponsesService;
use App\Services\SalesToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function responsesServiceForTest(?string $apiKey = null): array
{
    $conversation = ConversationSession::factory()->ongoing()->create([
        'customer_name' => 'Ada',
        'customer_company' => 'Analytical Engines',
        'template_used' => null,
    ]);
    $capture = MeetingCaptureSession::query()->create([
        'conversation_session_id' => $conversation->id,
        'provider' => MeetingProvider::Recall,
        'status' => MeetingCaptureStatus::Active,
        'idempotency_key' => (string) str()->uuid(),
    ]);
    $participant = MeetingParticipant::query()->create([
        'meeting_capture_session_id' => $capture->id,
        'provider_participant_id' => 'customer-1',
        'display_name' => 'Ada',
        'is_bot' => false,
        'sales_role' => SalesRole::Customer,
    ]);
    $transcript = ConversationTranscript::query()->create([
        'session_id' => $conversation->id,
        'meeting_capture_session_id' => $capture->id,
        'meeting_participant_id' => $participant->id,
        'provider' => MeetingProvider::Recall,
        'provider_item_id' => 'item-1',
        'speaker' => SalesRole::Customer,
        'source_stream' => 'recall',
        'text' => 'Our reporting is entirely manual.',
        'spoken_at' => now(),
        'status' => 'final',
        'order_index' => 1,
        'started_offset_ms' => 100,
        'ended_offset_ms' => 200,
    ]);
    $delivery = MeetingAnalysisDelivery::query()->create([
        'meeting_capture_session_id' => $capture->id,
        'conversation_transcript_id' => $transcript->id,
        'status' => 'processing',
        'lease_token' => (string) str()->uuid(),
        'leased_at' => now(),
        'attempts' => 1,
        'evidence_snapshot' => [
            'current_provider_item_id' => 'item-1',
            'prior_provider_item_ids' => [],
        ],
    ]);

    $keys = Mockery::mock(ApiKeyService::class);
    $keys->shouldReceive('getApiKey')->andReturn($apiKey);
    $sessions = Mockery::mock(CopilotSessionService::class);
    $sessions->shouldReceive('instructionsFor')->andReturn('Silent copilot instructions.');
    $tools = Mockery::mock(SalesToolRegistry::class);
    $tools->shouldReceive('recallResponsesTools')->andReturn([[
        'type' => 'function',
        'name' => 'capture_pain_point',
        'description' => 'Capture pain.',
        'parameters' => [
            'type' => 'object',
            'properties' => [],
            'required' => [],
            'additionalProperties' => false,
        ],
    ]]);
    $context = new MeetingAnalysisContextService;

    return [
        new OpenAIResponsesService($keys, $sessions, $tools, $context),
        $delivery,
    ];
}

it('requires a server-side OpenAI key for Recall Responses analysis', function () {
    [$service, $delivery] = responsesServiceForTest();

    expect(fn () => $service->analyze($delivery))
        ->toThrow(MissingOpenAIKeyException::class);
});

it('sanitizes Responses API errors without retaining provider messages', function () {
    config()->set('openai.recall_analysis.retries', 0);
    Http::fake([
        '*' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'invalid_tool',
                'param' => 'tools.0',
                'message' => 'Rejected secret credential',
            ],
            'secret' => 'provider-secret',
        ], 400),
    ]);
    [$service, $delivery] = responsesServiceForTest(mockApiKey());

    try {
        $service->analyze($delivery);
        $this->fail('Expected OpenAIResponsesException.');
    } catch (OpenAIResponsesException $exception) {
        expect($exception->statusCode())->toBe(400)
            ->and($exception->safePayload())->toBe([
                'error' => [
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_tool',
                    'param' => 'tools.0',
                ],
            ])
            ->and(json_encode($exception->safePayload()))->not->toContain('secret');
    }
});
