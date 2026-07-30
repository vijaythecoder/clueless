<?php

use App\Data\MeetingCaptureStartData;
use App\Exceptions\AmbiguousRecallCreateException;
use App\Exceptions\RecallApiException;
use App\Exceptions\RecallCapacityException;
use App\Services\Recall\RecallApiClient;
use App\Services\Recall\RecallCaptureProvider;
use App\Services\Recall\RecallCredentialService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('services.recall.api_key', 'recall-api-key-for-tests');
    Config::set('services.recall.webhook_secret', 'whsec_for_tests');
    Config::set('services.recall.base_url', 'https://us-east-1.recall.test');
    Config::set('services.recall.webhook_url', 'https://clueless.test/api/recall/webhooks');
    Config::set('services.recall.connect_timeout', 5);
    Config::set('services.recall.timeout', 15);
    Config::set('services.recall.partial_transcripts', false);

    $this->data = new MeetingCaptureStartData(
        captureId: '0198270c-c0de-7000-8000-000000000001',
        meetingUrl: 'https://teams.microsoft.com/l/meetup-join/secret-meeting-url',
        idempotencyKey: '0198270c-c0de-7000-8000-000000000002',
    );
    $this->client = fn (?Closure $sleep = null) => new RecallApiClient(
        app(RecallCredentialService::class),
        $sleep,
    );
});

it('creates a bot with exact low latency transcription and metadata payload', function () {
    Http::fake(['*' => Http::response(['id' => 'bot-123', 'status' => 'ready'], 201)]);

    expect(($this->client)()->create($this->data))->toMatchArray(['id' => 'bot-123', 'status' => 'ready']);

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://us-east-1.recall.test/api/v1/bot/'
            && $request->data() === [
                'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/secret-meeting-url',
                'bot_name' => 'Clueless Copilot',
                'metadata' => [
                    'clueless_capture_id' => '0198270c-c0de-7000-8000-000000000001',
                    'clueless_idempotency_key' => '0198270c-c0de-7000-8000-000000000002',
                ],
                'recording_config' => [
                    'transcript' => [
                        'provider' => [
                            'recallai_streaming' => [
                                'mode' => 'prioritize_low_latency',
                                'language_code' => 'en',
                            ],
                        ],
                        'diarization' => [
                            'use_separate_streams_when_available' => true,
                        ],
                    ],
                    'realtime_endpoints' => [[
                        'type' => 'webhook',
                        'url' => 'https://clueless.test/api/recall/webhooks',
                        'events' => [
                            'participant_events.join',
                            'participant_events.update',
                            'participant_events.leave',
                            'transcript.data',
                        ],
                    ]],
                ],
            ];
    });
});

it('adds partial events only when enabled', function () {
    Config::set('services.recall.partial_transcripts', true);
    Http::fake(['*' => Http::response(['id' => 'bot-123'], 201)]);

    ($this->client)()->create($this->data);

    Http::assertSent(fn (Request $request) => in_array(
        'transcript.partial_data',
        $request->data()['recording_config']['realtime_endpoints'][0]['events'],
        true,
    ));
});

it('uses the raw authorization key and configured region base url', function () {
    Http::fake(['*' => Http::response(['id' => 'bot-123'], 201)]);

    ($this->client)()->create($this->data);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://us-east-1.recall.test/api/v1/bot/'
        && $request->header('Authorization')[0] === 'recall-api-key-for-tests'
        && ! str_contains($request->header('Authorization')[0], 'Bearer'));
});

it('retries 429 using capped retry after', function () {
    $delays = [];
    Http::fakeSequence()
        ->push([], 429, ['Retry-After' => '12'])
        ->push([], 429, ['Retry-After' => '2'])
        ->push(['id' => 'bot-123'], 201);

    expect(($this->client)(function (int $seconds) use (&$delays): void {
        $delays[] = $seconds;
    })->create($this->data))->toMatchArray(['id' => 'bot-123'])
        ->and($delays)->toBe([5, 2]);

    Http::assertSentCount(3);
});

it('retries 429 for non-create Recall requests', function (string $operation) {
    $delays = [];
    $successResponse = match ($operation) {
        'lookup' => ['results' => []],
        'retrieve' => ['id' => 'bot-123', 'status' => 'in_call_recording'],
        'leave' => [],
    };
    $successStatus = $operation === 'leave' ? 204 : 200;

    Http::fakeSequence()
        ->push([], 429, ['Retry-After' => '9'])
        ->push($successResponse, $successStatus);

    $client = ($this->client)(function (int $seconds) use (&$delays): void {
        $delays[] = $seconds;
    });

    match ($operation) {
        'lookup' => $client->findByIdempotencyKey($this->data->idempotencyKey),
        'retrieve' => $client->retrieve('bot-123'),
        'leave' => $client->leave('bot-123'),
    };

    expect($delays)->toBe([5]);
    Http::assertSentCount(2);
})->with(['lookup', 'retrieve', 'leave']);

it('maps 507 without retrying', function () {
    Http::fake(['*' => Http::response(['detail' => 'capacity full'], 507)]);

    try {
        ($this->client)()->create($this->data);
    } catch (RecallCapacityException $exception) {
        expect($exception->getMessage())->toBe('Recall capture capacity is unavailable.')
            ->and($exception->safeCode())->toBe('recall_capacity_unavailable');

        Http::assertSentCount(1);

        return;
    }

    $this->fail('Expected a Recall capacity exception.');
});

it('does not retry authentication or validation failures', function (int $status) {
    Http::fake(['*' => Http::response(['detail' => 'sensitive provider response'], $status)]);

    expect(fn () => ($this->client)()->create($this->data))->toThrow(RecallApiException::class);

    Http::assertSentCount(1);
})->with([400, 401, 402, 403]);

it('recovers a timed out create by metadata without a second post', function () {
    $posts = 0;
    Http::fake(function (Request $request) use (&$posts) {
        if ($request->method() === 'POST') {
            $posts++;

            throw new ConnectionException('connection timed out');
        }

        return Http::response([
            'results' => [[
                'id' => 'bot-recovered',
                'metadata' => ['clueless_idempotency_key' => '0198270c-c0de-7000-8000-000000000002'],
            ]],
        ]);
    });

    expect(($this->client)()->create($this->data))->toMatchArray(['id' => 'bot-recovered'])
        ->and($posts)->toBe(1);
});

it('throws a safe ambiguous exception when recovery finds no bot', function () {
    $posts = 0;
    Http::fake(function (Request $request) use (&$posts) {
        if ($request->method() === 'POST') {
            $posts++;

            throw new ConnectionException('connection timed out');
        }

        return Http::response(['results' => []]);
    });

    expect(fn () => ($this->client)()->create($this->data))
        ->toThrow(AmbiguousRecallCreateException::class, 'Recall bot creation could not be confirmed');

    expect($posts)->toBe(1);
});

it('does not create a bot when metadata recovery finds multiple bots', function () {
    Http::fake(['*' => Http::response(['results' => [
        ['id' => 'bot-one', 'metadata' => ['clueless_idempotency_key' => '0198270c-c0de-7000-8000-000000000002']],
        ['id' => 'bot-two', 'metadata' => ['clueless_idempotency_key' => '0198270c-c0de-7000-8000-000000000002']],
    ]])]);

    try {
        (new RecallCaptureProvider(($this->client)()))->start($this->data);
    } catch (AmbiguousRecallCreateException $exception) {
        expect($exception->getMessage())->toBe('Recall bot recovery found multiple matches.')
            ->and($exception->safeCode())->toBe('recall_create_multiple_matches')
            ->and($exception->isRecoverable())->toBeFalse();

        Http::assertSentCount(1);

        return;
    }

    $this->fail('Expected a multiple-match recovery exception.');
});

it('serializes concurrent list then create with a cache lock', function () {
    $callbackRan = false;
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')
        ->once()
        ->with(5, Mockery::type(Closure::class))
        ->andReturnUsing(function (int $seconds, Closure $callback) use (&$callbackRan) {
            $callbackRan = true;

            return $callback();
        });

    Cache::shouldReceive('lock')
        ->once()
        ->with('recall-bot-create:0198270c-c0de-7000-8000-000000000002', 30)
        ->andReturn($lock);

    Http::fakeSequence()
        ->push(['results' => []])
        ->push(['id' => 'bot-created', 'status' => 'ready'], 201);

    $bot = (new RecallCaptureProvider(($this->client)()))->start($this->data);

    expect($bot['id'])->toBe('bot-created')
        ->and($callbackRan)->toBeTrue();
    Http::assertSentCount(2);
});

it('maps a create lock timeout to a safe retryable error', function () {
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')
        ->once()
        ->andThrow(new LockTimeoutException);

    Cache::shouldReceive('lock')->once()->andReturn($lock);

    try {
        (new RecallCaptureProvider(($this->client)()))->start($this->data);
    } catch (RecallApiException $exception) {
        expect($exception->safeCode())->toBe('recall_create_busy')
            ->and($exception->getMessage())->toBe('Recall bot creation is busy. Please retry.');

        Http::assertNothingSent();

        return;
    }

    $this->fail('Expected a safe Recall API exception.');
});

it('never exposes the api key or meeting url in errors', function () {
    Http::fake(['*' => Http::response(['detail' => 'recall-api-key-for-tests https://teams.microsoft.com/l/meetup-join/secret-meeting-url'], 401)]);

    try {
        ($this->client)()->create($this->data);
    } catch (RecallApiException $exception) {
        expect($exception->getMessage())
            ->not->toContain('recall-api-key-for-tests')
            ->not->toContain('secret-meeting-url');

        return;
    }

    $this->fail('Expected a sanitized Recall API exception.');
});
