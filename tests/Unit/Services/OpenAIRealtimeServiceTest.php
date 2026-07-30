<?php

use App\Exceptions\RealtimeClientSecretException;
use App\Services\ApiKeyService;
use App\Services\CopilotSessionService;
use App\Services\OpenAIRealtimeService;
use Illuminate\Support\Facades\Http;

function realtimeServiceForTest(array $session = ['type' => 'realtime']): OpenAIRealtimeService
{
    $apiKeyService = Mockery::mock(ApiKeyService::class);
    $apiKeyService->shouldReceive('getApiKey')->andReturn(mockApiKey());

    $copilotSessionService = Mockery::mock(CopilotSessionService::class);
    $copilotSessionService->shouldReceive('build')->andReturn($session);

    return new OpenAIRealtimeService($apiKeyService, $copilotSessionService);
}

test('uses configured base URL timeouts retries and a stable non-PII safety identifier', function () {
    config()->set([
        'app.key' => 'base64:testing-application-key',
        'openai.realtime.base_url' => 'https://realtime.example.test/v1/',
        'openai.realtime.timeout' => 17,
        'openai.realtime.connect_timeout' => 4,
        'openai.realtime.retries' => 1,
        'openai.realtime.retry_delay_ms' => 0,
        'openai.realtime.safety_identifier_seed' => 'clueless-installation',
    ]);

    $seenOptions = [];
    Http::globalMiddleware(function (callable $handler) use (&$seenOptions) {
        return function ($request, array $options) use ($handler, &$seenOptions) {
            $seenOptions[] = $options;

            return $handler($request, $options);
        };
    });

    Http::fakeSequence()
        ->push(['error' => ['type' => 'server_error']], 500)
        ->push(mockRealtimeClientSecretResponse(), 200)
        ->push(mockRealtimeClientSecretResponse(), 200);

    $service = realtimeServiceForTest();
    $first = $service->createClientSecret('copilot', context: ['safety_identifier' => 'person@example.com']);
    $second = $service->createClientSecret('copilot', context: ['safety_identifier' => 'another-person']);

    expect($first['clientSecret'])->toStartWith('ek_')
        ->and($second['clientSecret'])->toStartWith('ek_')
        ->and(Http::recorded())->toHaveCount(3)
        ->and($seenOptions[0]['timeout'])->toBe(17)
        ->and($seenOptions[0]['connect_timeout'])->toBe(4);

    $requests = Http::recorded()->map(fn (array $pair) => $pair[0]);
    $safetyIdentifiers = $requests
        ->map(fn ($request) => $request->header('OpenAI-Safety-Identifier')[0])
        ->unique()
        ->values();

    expect($requests->every(fn ($request) => $request->url() === 'https://realtime.example.test/v1/realtime/client_secrets'))->toBeTrue()
        ->and($safetyIdentifiers)->toHaveCount(1)
        ->and($safetyIdentifiers[0])->toMatch('/^[a-f0-9]{64}$/')
        ->and($safetyIdentifiers[0])->not->toContain('person');
});

test('returns only renderer-safe session fields', function () {
    Http::fake([
        '*' => Http::response(mockRealtimeClientSecretResponse([
            'session' => [
                'type' => 'realtime',
                'object' => 'realtime.session',
                'id' => 'sess_safe',
                'model' => 'gpt-realtime-2.1',
                'output_modalities' => ['text'],
                'expires_at' => 123,
                'instructions' => 'private prompt',
                'tools' => [[
                    'type' => 'mcp',
                    'authorization' => 'secret-token',
                    'headers' => ['Authorization' => 'Bearer secret'],
                ]],
            ],
        ])),
    ]);

    $result = realtimeServiceForTest()->createClientSecret('copilot');

    expect($result['session'])->toEqual([
        'id' => 'sess_safe',
        'object' => 'realtime.session',
        'type' => 'realtime',
        'model' => 'gpt-realtime-2.1',
        'output_modalities' => ['text'],
        'expires_at' => 123,
    ])->not->toHaveKeys(['instructions', 'tools', 'audio'])
        ->and($result['mcpToolCount'])->toBe(0);
});

test('sanitizes upstream error payloads', function () {
    config()->set('openai.realtime.retries', 0);

    Http::fake([
        '*' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'invalid_tool',
                'param' => 'session.tools.0.authorization',
                'message' => 'Rejected token secret-token',
            ],
            'authorization' => 'secret-token',
        ], 400),
    ]);

    try {
        realtimeServiceForTest()->createClientSecret('copilot');
        $this->fail('Expected RealtimeClientSecretException.');
    } catch (RealtimeClientSecretException $exception) {
        expect($exception->payload())->toBe([
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'invalid_tool',
                'param' => 'session.tools.0.authorization',
            ],
        ])->and(json_encode($exception->payload()))->not->toContain('secret-token');
    }
});

test('sanitizes scalar JSON upstream failures', function () {
    config()->set('openai.realtime.retries', 0);

    Http::fake([
        '*' => Http::response(
            json_encode('gateway leaked secret', JSON_THROW_ON_ERROR),
            502,
            ['Content-Type' => 'application/json'],
        ),
    ]);

    try {
        realtimeServiceForTest()->createClientSecret('copilot');
        $this->fail('Expected RealtimeClientSecretException.');
    } catch (RealtimeClientSecretException $exception) {
        expect($exception->payload())->toBe([
            'error' => ['type' => 'upstream_error'],
        ]);
    }
});
