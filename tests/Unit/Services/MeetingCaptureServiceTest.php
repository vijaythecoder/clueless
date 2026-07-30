<?php

use App\Enums\MeetingCaptureStatus;
use App\Enums\MeetingProvider;
use App\Exceptions\AmbiguousRecallCreateException;
use App\Exceptions\RecallApiException;
use App\Models\ConversationSession;
use App\Models\MeetingCaptureSession;
use App\Services\MeetingCaptureService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('services.recall.api_key', 'recall-api-key-for-tests');
    Config::set('services.recall.webhook_secret', 'whsec_for_tests');
    Config::set('services.recall.base_url', 'https://us-east-1.recall.test');
    Config::set('services.recall.webhook_url', 'https://clueless.test/api/recall/webhooks');

    $this->providerState = 'ready';
    $this->providerBotId = 'bot-123';
    $this->providerResponseExtra = [];
    $this->lookupCount = 0;
    $this->lookupBots = [];
    $this->delayedBotKey = null;
    $this->delayedBotAfterLookup = PHP_INT_MAX;
    $this->createConnectionFails = false;
    $this->leaveOutcomes = [];
    $this->leaveAttempts = 0;

    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            $this->lookupCount++;

            if ($this->delayedBotKey !== null && $this->lookupCount >= $this->delayedBotAfterLookup) {
                return Http::response(['results' => [[
                    'id' => 'bot-delayed',
                    'status' => 'joining_call',
                    'metadata' => ['clueless_idempotency_key' => $this->delayedBotKey],
                ]]]);
            }

            return Http::response(['results' => $this->lookupBots]);
        }

        if (str_ends_with($request->url(), '/leave_call/')) {
            $this->leaveAttempts++;
            $outcome = array_shift($this->leaveOutcomes) ?? 204;

            if ($outcome === 'connection') {
                throw new ConnectionException('connection timed out');
            }

            return Http::response([], $outcome);
        }

        if ($this->createConnectionFails) {
            throw new ConnectionException('connection timed out');
        }

        return Http::response(array_merge([
            'id' => $this->providerBotId,
            'status' => $this->providerState,
        ], $this->providerResponseExtra), 201);
    });

    $this->service = app(MeetingCaptureService::class);
    $this->conversation = ConversationSession::factory()->ongoing()->create();
});

it('stores only the meeting url hash', function () {
    $meetingUrl = 'https://teams.microsoft.com/l/meetup-join/secret-meeting-url';

    $capture = $this->service->startRecall(
        $this->conversation,
        $meetingUrl,
        '0198270c-c0de-7000-8000-000000000003',
    );

    expect($capture->meeting_url_hash)->toBe(hash('sha256', $meetingUrl))
        ->and($capture->getAttributes())->not->toHaveKey('meeting_url')
        ->and($capture->provider_bot_id)->toBe('bot-123');
});

it('resolves MeetingCaptureService from the Laravel container', function () {
    expect(app(MeetingCaptureService::class))->toBeInstanceOf(MeetingCaptureService::class);
});

it('rejects and never sends non Teams meeting urls', function () {
    expect(fn () => $this->service->startRecall(
        $this->conversation,
        'https://attacker.example/meeting',
        '0198270c-c0de-7000-8000-000000000099',
    ))->toThrow(ValidationException::class);

    expect(MeetingCaptureSession::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('canonicalizes the Teams URL before hashing and provider creation', function () {
    $capture = $this->service->startRecall(
        $this->conversation,
        'HTTPS://TEAMS.MICROSOFT.COM/l/meetup-join/abc?context=token#invite-fragment',
        '0198270c-c0de-7000-8000-000000000098',
    );

    expect($capture->meeting_url_hash)
        ->toBe(hash('sha256', 'https://teams.microsoft.com/l/meetup-join/abc?context=token'))
        ->and(Http::recorded(fn (Request $request) => $request->method() === 'POST'
            && $request->data()['meeting_url'] === 'https://teams.microsoft.com/l/meetup-join/abc?context=token'))
        ->toHaveCount(1);
});

it('accepts explicit HTTPS port 443 and canonicalizes it away', function () {
    $capture = $this->service->startRecall(
        $this->conversation,
        'https://teams.microsoft.com:443/l/meetup-join/explicit-port?context=token',
        '0198270c-c0de-7000-8000-000000000094',
    );

    expect($capture->meeting_url_hash)
        ->toBe(hash('sha256', 'https://teams.microsoft.com/l/meetup-join/explicit-port?context=token'))
        ->and(Http::recorded(fn (Request $request) => $request->method() === 'POST'
            && $request->data()['meeting_url'] === 'https://teams.microsoft.com/l/meetup-join/explicit-port?context=token'))
        ->toHaveCount(1);
});

it('rejects non-443 Teams URL ports before persistence or HTTP', function () {
    expect(fn () => $this->service->startRecall(
        $this->conversation,
        'https://teams.microsoft.com:444/l/meetup-join/wrong-port',
        '0198270c-c0de-7000-8000-000000000093',
    ))->toThrow(ValidationException::class);

    expect(MeetingCaptureSession::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('returns an existing capture for a repeated idempotency key', function () {
    $key = '0198270c-c0de-7000-8000-000000000004';

    $first = $this->service->startRecall($this->conversation, 'https://teams.microsoft.com/first', $key);
    $second = $this->service->startRecall(
        $this->conversation,
        'HTTPS://TEAMS.MICROSOFT.COM:443/first#invite-fragment',
        $key,
    );

    expect($second->id)->toBe($first->id)
        ->and($second->meeting_url_hash)->toBe(hash('sha256', 'https://teams.microsoft.com/first'));

    Http::assertSentCount(2);
});

it('rejects a different URL for an idempotency key with a provider bot', function () {
    $key = '0198270c-c0de-7000-8000-000000000092';
    $this->service->startRecall(
        $this->conversation,
        'https://teams.microsoft.com/l/meetup-join/bound-url-a',
        $key,
    );

    try {
        $this->service->startRecall(
            $this->conversation,
            'https://teams.microsoft.com/l/meetup-join/bound-url-b',
            $key,
        );
    } catch (RecallApiException $exception) {
        expect($exception->safeCode())->toBe('recall_idempotency_conflict')
            ->and($exception->getMessage())->toBe('The idempotency key is already bound to another meeting.');

        Http::assertSentCount(2);

        return;
    }

    $this->fail('Expected an idempotency URL conflict.');
});

it('rejects a different URL for an ambiguous recoverable capture before HTTP', function () {
    $key = '0198270c-c0de-7000-8000-000000000091';
    MeetingCaptureSession::query()->create([
        'conversation_session_id' => $this->conversation->id,
        'provider' => MeetingProvider::Recall,
        'platform' => 'teams',
        'status' => MeetingCaptureStatus::Creating,
        'meeting_url_hash' => hash('sha256', 'https://teams.microsoft.com/l/meetup-join/bound-url-a'),
        'idempotency_key' => $key,
        'failure_code' => 'recall_create_ambiguous',
        'failure_message' => 'Recall bot creation could not be confirmed.',
    ]);

    try {
        $this->service->startRecall(
            $this->conversation,
            'https://teams.microsoft.com/l/meetup-join/bound-url-b',
            $key,
        );
    } catch (RecallApiException $exception) {
        expect($exception->safeCode())->toBe('recall_idempotency_conflict');
        Http::assertNothingSent();

        return;
    }

    $this->fail('Expected an idempotency URL conflict.');
});

it('recovers a delayed bot on a later repeated start', function () {
    $key = '0198270c-c0de-7000-8000-000000000097';
    $this->delayedBotKey = $key;
    $this->delayedBotAfterLookup = 3;
    $this->createConnectionFails = true;

    expect(fn () => $this->service->startRecall(
        $this->conversation,
        'https://teams.microsoft.com/l/meetup-join/delayed',
        $key,
    ))->toThrow(AmbiguousRecallCreateException::class);

    $capture = MeetingCaptureSession::query()
        ->where('idempotency_key', $key)
        ->firstOrFail();

    expect($capture->status)->toBe(MeetingCaptureStatus::Creating)
        ->and($capture->failure_code)->toBe('recall_create_ambiguous')
        ->and($this->lookupCount)->toBe(2);

    $recovered = $this->service->startRecall(
        $this->conversation,
        'https://teams.microsoft.com/l/meetup-join/delayed',
        $key,
    );

    expect($recovered->provider_bot_id)->toBe('bot-delayed')
        ->and($recovered->status)->toBe(MeetingCaptureStatus::Joining)
        ->and($this->lookupCount)->toBe(3);
});

it('keeps a create lock timeout nonterminal for retry', function () {
    $key = '0198270c-c0de-7000-8000-000000000096';
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
    Cache::shouldReceive('lock')->once()->andReturn($lock);

    expect(fn () => $this->service->startRecall(
        $this->conversation,
        'https://teams.microsoft.com/l/meetup-join/busy',
        $key,
    ))->toThrow(RecallApiException::class);

    $capture = MeetingCaptureSession::query()
        ->where('idempotency_key', $key)
        ->firstOrFail();

    expect($capture->status)->toBe(MeetingCaptureStatus::Creating)
        ->and($capture->failure_code)->toBe('recall_create_busy');
});

it('marks multiple recovered bots as a terminal safe failure', function () {
    $key = '0198270c-c0de-7000-8000-000000000095';
    $this->lookupBots = [
        ['id' => 'bot-one', 'metadata' => ['clueless_idempotency_key' => $key]],
        ['id' => 'bot-two', 'metadata' => ['clueless_idempotency_key' => $key]],
    ];

    expect(fn () => $this->service->startRecall(
        $this->conversation,
        'https://teams.microsoft.com/l/meetup-join/duplicate',
        $key,
    ))->toThrow(AmbiguousRecallCreateException::class);

    $capture = MeetingCaptureSession::query()
        ->where('idempotency_key', $key)
        ->firstOrFail();

    expect($capture->status)->toBe(MeetingCaptureStatus::Failed)
        ->and($capture->failure_code)->toBe('recall_create_multiple_matches');
});

it('stops active bots idempotently', function () {
    $capture = $this->service->startRecall(
        $this->conversation,
        'https://teams.microsoft.com/meeting',
        '0198270c-c0de-7000-8000-000000000005',
    );
    $capture->update(['status' => MeetingCaptureStatus::Active]);

    $this->service->stop($capture);
    $this->service->stop($capture->fresh());

    $leaveRequests = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/leave_call/'));

    expect($leaveRequests)->toHaveCount(1)
        ->and($this->leaveAttempts)->toBe(1)
        ->and($capture->fresh()->status)->toBe(MeetingCaptureStatus::Stopping)
        ->and($capture->fresh()->failure_code)->toBeNull()
        ->and($capture->fresh()->failure_message)->toBeNull();
});

it('retries a stopping capture after leave failure', function (
    int|string $firstOutcome,
    string $failureCode,
    string $failureMessage,
) {
    $capture = $this->service->startRecall(
        $this->conversation,
        'https://teams.microsoft.com/l/meetup-join/stop-retry',
        fake()->uuid(),
    );
    $capture->update(['status' => MeetingCaptureStatus::Active]);
    $this->leaveOutcomes = [$firstOutcome, 204];

    expect(fn () => $this->service->stop($capture))
        ->toThrow(RecallApiException::class);

    $failedStop = $capture->fresh();

    expect($failedStop->status)->toBe(MeetingCaptureStatus::Stopping)
        ->and($failedStop->failure_code)->toBe($failureCode)
        ->and($failedStop->failure_message)->toBe($failureMessage);

    $retried = $this->service->stop($failedStop);

    expect($this->leaveAttempts)->toBe(2)
        ->and($retried->status)->toBe(MeetingCaptureStatus::Stopping)
        ->and($retried->failure_code)->toBeNull()
        ->and($retried->failure_message)->toBeNull();
})->with([
    'server error then success' => [500, 'recall_http_500', 'Recall capture request failed.'],
    'connection error then success' => ['connection', 'recall_stop_failed', 'Unable to stop Recall capture.'],
]);

it('accepts terminal leave responses and keeps later stops idempotent', function (int $status) {
    $capture = $this->service->startRecall(
        $this->conversation,
        'https://teams.microsoft.com/l/meetup-join/already-stopped',
        fake()->uuid(),
    );
    $capture->update(['status' => MeetingCaptureStatus::Active]);
    $this->leaveOutcomes = [$status, 500];

    $stopping = $this->service->stop($capture);
    $repeated = $this->service->stop($stopping);

    expect($this->leaveAttempts)->toBe(1)
        ->and($repeated->status)->toBe(MeetingCaptureStatus::Stopping)
        ->and($repeated->failure_code)->toBeNull()
        ->and($repeated->failure_message)->toBeNull();
})->with([400, 404, 405]);

it('maps documented Recall lifecycle states', function (string $providerState, MeetingCaptureStatus $expected) {
    $this->providerState = $providerState;

    $capture = $this->service->startRecall(
        $this->conversation,
        'https://teams.microsoft.com/meeting',
        fake()->uuid(),
    );

    expect($capture->status)->toBe($expected);
})->with([
    ['ready', MeetingCaptureStatus::Creating],
    ['joining_call', MeetingCaptureStatus::Joining],
    ['in_waiting_room', MeetingCaptureStatus::WaitingRoom],
    ['in_call_not_recording', MeetingCaptureStatus::Joining],
    ['recording_permission_allowed', MeetingCaptureStatus::Joining],
    ['in_call_recording', MeetingCaptureStatus::Active],
    ['call_ended', MeetingCaptureStatus::Ended],
    ['done', MeetingCaptureStatus::Ended],
    ['recording_permission_denied', MeetingCaptureStatus::Failed],
    ['fatal', MeetingCaptureStatus::Failed],
]);

it('stores safe diagnostics for terminal failed create states', function (
    string $providerState,
    string $expectedCode,
    string $expectedMessage,
) {
    $this->providerState = $providerState;
    $this->providerResponseExtra = [
        'detail' => 'raw provider body with patient@example.test and secret-token',
    ];

    $capture = $this->service->startRecall(
        $this->conversation,
        'https://teams.microsoft.com/l/meetup-join/terminal-create',
        fake()->uuid(),
    );

    expect($capture->status)->toBe(MeetingCaptureStatus::Failed)
        ->and($capture->failure_code)->toBe($expectedCode)
        ->and($capture->failure_message)->toBe($expectedMessage)
        ->and($capture->failure_message)->not->toContain('patient@example.test')
        ->not->toContain('secret-token');
})->with([
    'permission denied' => [
        'recording_permission_denied',
        'recall_recording_permission_denied',
        'Recall recording permission was denied.',
    ],
    'fatal' => [
        'fatal',
        'recall_fatal',
        'Recall reported a fatal bot failure.',
    ],
]);
