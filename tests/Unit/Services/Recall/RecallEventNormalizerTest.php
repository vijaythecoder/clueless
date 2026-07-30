<?php

use App\Enums\MeetingCaptureStatus;
use App\Services\Recall\RecallEventNormalizer;

function recallFixture(string $name): array
{
    $contents = file_get_contents(base_path("tests/Fixtures/Recall/{$name}.json"));

    if ($contents === false) {
        throw new RuntimeException("Unable to load Recall fixture [{$name}].");
    }

    return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
}

it('normalizes current final and partial payloads', function () {
    $normalizer = app(RecallEventNormalizer::class);

    $final = $normalizer->normalize(recallFixture('transcript-final'));
    $partial = $normalizer->normalize(recallFixture('transcript-partial'));

    expect($final)
        ->eventType->toBe('transcript.final')
        ->providerEventId->toBe('artifact_final_123')
        ->providerParticipantId->toBe('42')
        ->displayName->toBe('Ada Lovelace')
        ->email->toBe('ada@example.test')
        ->isHost->toBeFalse()
        ->isBot->toBeFalse()
        ->text->toBe('Final transcript text')
        ->startOffsetMs->toBe(1200)
        ->endOffsetMs->toBe(3100)
        ->providerOccurredAt->toBeNull()
        ->isFinalTranscript->toBeTrue()
        ->isPartialTranscript->toBeFalse()
        ->isEmptyFinal->toBeFalse()
        ->payload->toMatchArray([
            'artifact_id' => 'artifact_final_123',
            'utterance_key' => '42:1200',
        ])
        ->and($final->payload)->not->toHaveKey('extra_data')
        ->and($partial)
        ->eventType->toBe('transcript.partial')
        ->text->toBe('Partial text')
        ->startOffsetMs->toBe(1200)
        ->endOffsetMs->toBe(1800)
        ->isPartialTranscript->toBeTrue()
        ->isFinalTranscript->toBeFalse();
});

it('derives stable final provider ids without confusing artifact ids', function () {
    $normalizer = app(RecallEventNormalizer::class);
    $event = recallFixture('transcript-final');
    $retry = $event;
    $retry['data']['transcript']['id'] = 'artifact_final_retry_456';

    $first = $normalizer->normalize($event);
    $second = $normalizer->normalize($retry);

    expect($first->providerItemId)
        ->toStartWith('recall_')
        ->toBe($second->providerItemId)
        ->not->toBe($first->providerEventId)
        ->and($first->utteranceKey)->toBe('42:1200');
});

it('normalizes participant and both lifecycle envelopes', function () {
    $normalizer = app(RecallEventNormalizer::class);

    $joined = $normalizer->normalize(recallFixture('participant-join'));
    $updated = $normalizer->normalize(recallFixture('participant-update'));
    $left = $normalizer->normalize(recallFixture('participant-leave'));
    $current = $normalizer->normalize(recallFixture('bot-status'));
    $legacy = $normalizer->normalize(recallFixture('legacy-bot-status'));

    expect($joined->eventType)->toBe('participant.join')
        ->and($joined->providerEventId)->toBe('participant_events_test_123')
        ->and($joined->providerParticipantId)->toBe('42')
        ->and($joined->providerOccurredAt?->toIso8601String())->toBe('2026-07-27T12:00:00+00:00')
        ->and($updated->eventType)->toBe('participant.update')
        ->and($updated->displayName)->toBe('Ada Byron Lovelace')
        ->and($left->eventType)->toBe('participant.leave')
        ->and($current->captureStatus)->toBe(MeetingCaptureStatus::Active)
        ->and($current->providerOccurredAt?->toIso8601String())->toBe('2026-07-27T12:00:02+00:00')
        ->and($legacy->captureStatus)->toBe(MeetingCaptureStatus::WaitingRoom)
        ->and($legacy->providerBotId)->toBe('bot_test_123');
});

it('normalizes current lifecycle failure sub codes', function () {
    $normalized = app(RecallEventNormalizer::class)->normalize([
        'event' => 'bot.fatal',
        'data' => [
            'bot' => ['id' => 'bot_test_123'],
            'data' => [
                'code' => 'fatal',
                'sub_code' => 'teams_meeting_not_found',
                'updated_at' => '2026-07-27T12:00:03Z',
            ],
        ],
    ]);

    expect($normalized)
        ->captureStatus->toBe(MeetingCaptureStatus::Failed)
        ->failureCode->toBe('teams_meeting_not_found')
        ->failureMessage->toBeNull()
        ->providerOccurredAt?->toIso8601String()->toBe('2026-07-27T12:00:03+00:00');
});

it('maps every documented lifecycle code and keeps unknown codes diagnostic', function () {
    $normalizer = app(RecallEventNormalizer::class);
    $expected = [
        'bot.joining_call' => ['joining_call', MeetingCaptureStatus::Joining],
        'bot.in_waiting_room' => ['in_waiting_room', MeetingCaptureStatus::WaitingRoom],
        'bot.in_call_not_recording' => ['in_call_not_recording', MeetingCaptureStatus::Joining],
        'bot.recording_permission_allowed' => ['recording_permission_allowed', MeetingCaptureStatus::Joining],
        'bot.in_call_recording' => ['in_call_recording', MeetingCaptureStatus::Active],
        'bot.call_ended' => ['call_ended', MeetingCaptureStatus::Ended],
        'bot.done' => ['done', MeetingCaptureStatus::Ended],
        'bot.recording_permission_denied' => ['recording_permission_denied', MeetingCaptureStatus::Failed],
        'bot.fatal' => ['fatal', MeetingCaptureStatus::Failed],
    ];

    foreach ($expected as $event => [$code, $status]) {
        expect($normalizer->normalize([
            'event' => $event,
            'data' => [
                'bot' => ['id' => 'bot_test_123'],
                'data' => [
                    'code' => $code,
                    'sub_code' => null,
                    'updated_at' => '2026-07-27T12:00:02Z',
                ],
            ],
        ])->captureStatus)->toBe($status);
    }

    $unknown = $normalizer->normalize([
        'event' => 'bot.future_status',
        'data' => [
            'bot' => ['id' => 'bot_test_123'],
            'data' => [
                'code' => 'future_status',
                'sub_code' => null,
                'updated_at' => '2026-07-27T12:00:02Z',
            ],
        ],
    ]);

    expect($unknown)
        ->eventType->toBe('bot.future_status')
        ->captureStatus->toBeNull();
});

it('returns null for unsupported events', function () {
    expect(app(RecallEventNormalizer::class)->normalize([
        'event' => 'recording.created',
        'data' => ['bot' => ['id' => 'bot_test_123']],
    ]))->toBeNull();
});
