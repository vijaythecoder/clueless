<?php

namespace App\Services\Recall;

use App\Data\NormalizedProviderEvent;
use App\Enums\MeetingCaptureStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use JsonException;

final class RecallEventNormalizer
{
    /** @var array<string, MeetingCaptureStatus> */
    private const LIFECYCLE_STATUSES = [
        'bot.joining_call' => MeetingCaptureStatus::Joining,
        'bot.in_waiting_room' => MeetingCaptureStatus::WaitingRoom,
        'bot.in_call_not_recording' => MeetingCaptureStatus::Joining,
        'bot.recording_permission_allowed' => MeetingCaptureStatus::Joining,
        'bot.in_call_recording' => MeetingCaptureStatus::Active,
        'bot.call_ended' => MeetingCaptureStatus::Ended,
        'bot.done' => MeetingCaptureStatus::Ended,
        'bot.recording_permission_denied' => MeetingCaptureStatus::Failed,
        'bot.fatal' => MeetingCaptureStatus::Failed,
    ];

    /**
     * @param  array<string, mixed>  $event
     */
    public function normalize(array $event): ?NormalizedProviderEvent
    {
        $providerEvent = $event['event'] ?? null;

        if (! is_string($providerEvent)) {
            return $this->unsupportedEvent();
        }

        /** @var array<string, mixed> $data */
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        return match ($providerEvent) {
            'transcript.data' => $this->transcript($data, true),
            'transcript.partial_data' => $this->transcript($data, false),
            'participant_events.join' => $this->participant($data, 'participant.join'),
            'participant_events.update' => $this->participant($data, 'participant.update'),
            'participant_events.leave' => $this->participant($data, 'participant.leave'),
            'bot.status_change' => $this->lifecycle($data, $providerEvent, true),
            default => array_key_exists($providerEvent, self::LIFECYCLE_STATUSES) || str_starts_with($providerEvent, 'bot.')
                ? $this->lifecycle($data, $providerEvent)
                : $this->unsupportedEvent($providerEvent),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function transcript(array $data, bool $isFinal): NormalizedProviderEvent
    {
        $eventData = $this->eventData($data);
        [$text, $startOffsetMs, $endOffsetMs] = $this->normalizedWords($eventData);
        $participant = $this->participantData($eventData);
        $participantId = $this->identifier($participant['id'] ?? null);
        $botId = $this->botId($data);
        $artifactId = $this->stringAt($data, 'transcript.id');
        $utteranceKey = $participantId !== null && $startOffsetMs !== null
            ? "{$participantId}:{$startOffsetMs}"
            : null;
        $isEmptyFinal = $isFinal && $text === null;
        $providerItemId = $isFinal && ! $isEmptyFinal
            ? $this->finalProviderItemId($botId, $participantId, $startOffsetMs, $endOffsetMs, $text)
            : null;

        $payload = $this->payload(
            eventType: $isFinal ? 'transcript.final' : 'transcript.partial',
            artifactId: $artifactId,
            utteranceKey: $utteranceKey,
            participantId: $participantId,
            displayName: $this->stringValue($participant['name'] ?? $participant['display_name'] ?? null),
            isHost: $this->nullableBoolean($participant['is_host'] ?? null),
            isBot: $this->boolean($participant['is_bot'] ?? false),
            text: $text,
            startOffsetMs: $startOffsetMs,
            endOffsetMs: $endOffsetMs,
            captureStatus: null,
            failureCode: null,
            failureMessage: null,
            isFinal: $isFinal,
            isPartial: ! $isFinal,
            isEmptyFinal: $isEmptyFinal,
        );

        return new NormalizedProviderEvent(
            eventType: $isFinal ? 'transcript.final' : 'transcript.partial',
            providerEventId: $artifactId,
            providerItemId: $providerItemId,
            utteranceKey: $utteranceKey,
            providerParticipantId: $participantId,
            displayName: $this->stringValue($participant['name'] ?? $participant['display_name'] ?? null),
            email: $this->stringValue($participant['email'] ?? null),
            isHost: $this->nullableBoolean($participant['is_host'] ?? null),
            isBot: $this->boolean($participant['is_bot'] ?? false),
            text: $text,
            startOffsetMs: $startOffsetMs,
            endOffsetMs: $endOffsetMs,
            providerOccurredAt: null,
            payload: $payload,
            captureStatus: null,
            failureCode: null,
            failureMessage: null,
            isFinalTranscript: $isFinal,
            isPartialTranscript: ! $isFinal,
            isEmptyFinal: $isEmptyFinal,
            providerBotId: $botId,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function participant(array $data, string $eventType): NormalizedProviderEvent
    {
        $eventData = $this->eventData($data);
        $participant = $this->participantData($eventData);
        $participantId = $this->identifier($participant['id'] ?? null);
        $displayName = $this->stringValue($participant['name'] ?? $participant['display_name'] ?? null);
        $isHost = $this->nullableBoolean($participant['is_host'] ?? null);
        $isBot = $this->boolean($participant['is_bot'] ?? false);

        return new NormalizedProviderEvent(
            eventType: $eventType,
            providerEventId: $this->stringAt($data, 'participant_events.id'),
            providerItemId: null,
            utteranceKey: null,
            providerParticipantId: $participantId,
            displayName: $displayName,
            email: $this->stringValue($participant['email'] ?? null),
            isHost: $isHost,
            isBot: $isBot,
            text: null,
            startOffsetMs: null,
            endOffsetMs: null,
            providerOccurredAt: $this->occurredAt($eventData, 'timestamp.absolute'),
            payload: $this->payload(
                eventType: $eventType,
                artifactId: null,
                utteranceKey: null,
                participantId: $participantId,
                displayName: $displayName,
                isHost: $isHost,
                isBot: $isBot,
                text: null,
                startOffsetMs: null,
                endOffsetMs: null,
                captureStatus: null,
                failureCode: null,
                failureMessage: null,
                isFinal: false,
                isPartial: false,
                isEmptyFinal: false,
            ),
            captureStatus: null,
            failureCode: null,
            failureMessage: null,
            isFinalTranscript: false,
            isPartialTranscript: false,
            isEmptyFinal: false,
            providerBotId: $this->botId($data),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function lifecycle(array $data, string $providerEvent, bool $legacy = false): NormalizedProviderEvent
    {
        $status = $legacy
            ? (is_array($data['status'] ?? null) ? $data['status'] : [])
            : $this->eventData($data);
        $statusCode = $this->stringValue($status['code'] ?? null);
        $eventType = $statusCode === null
            ? $providerEvent
            : (str_starts_with($statusCode, 'bot.') ? $statusCode : "bot.{$statusCode}");
        $captureStatus = self::LIFECYCLE_STATUSES[$eventType] ?? null;
        $failureCode = $captureStatus === MeetingCaptureStatus::Failed
            ? $this->stringValue($status['sub_code'] ?? null)
            : null;
        $failureMessage = $captureStatus === MeetingCaptureStatus::Failed
            ? $this->stringValue($status['message'] ?? null)
            : null;

        return new NormalizedProviderEvent(
            eventType: $eventType,
            providerEventId: $this->stringValue($data['id'] ?? null),
            providerItemId: null,
            utteranceKey: null,
            providerParticipantId: null,
            displayName: null,
            email: null,
            isHost: null,
            isBot: true,
            text: null,
            startOffsetMs: null,
            endOffsetMs: null,
            providerOccurredAt: $legacy
                ? $this->occurredAt($status, 'created_at') ?? $this->occurredAt($data, 'timestamp')
                : $this->occurredAt($status, 'updated_at'),
            payload: $this->payload(
                eventType: $eventType,
                artifactId: null,
                utteranceKey: null,
                participantId: null,
                displayName: null,
                isHost: null,
                isBot: true,
                text: null,
                startOffsetMs: null,
                endOffsetMs: null,
                captureStatus: $captureStatus,
                failureCode: $failureCode,
                failureMessage: $failureMessage,
                isFinal: false,
                isPartial: false,
                isEmptyFinal: false,
            ),
            captureStatus: $captureStatus,
            failureCode: $failureCode,
            failureMessage: $failureMessage,
            isFinalTranscript: false,
            isPartialTranscript: false,
            isEmptyFinal: false,
            providerBotId: $this->botId($data),
        );
    }

    private function unsupportedEvent(?string $eventType = null): null
    {
        Log::info('recall.webhook.unsupported_event', ['event_type' => $eventType]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: ?int, 2: ?int}
     */
    private function normalizedWords(array $data): array
    {
        $words = $data['words'] ?? [];
        $tokens = [];
        $starts = [];
        $ends = [];

        if (! is_array($words)) {
            return [null, null, null];
        }

        foreach ($words as $word) {
            if (! is_array($word)) {
                continue;
            }

            $text = $this->normalizedText($word['text'] ?? null);

            if ($text !== null) {
                $tokens[] = $text;
            }

            $start = $this->offsetMilliseconds($this->relativeSeconds($word['start_timestamp'] ?? null));
            $end = $this->offsetMilliseconds($this->relativeSeconds($word['end_timestamp'] ?? null));

            if ($start !== null) {
                $starts[] = $start;
            }

            if ($end !== null) {
                $ends[] = $end;
            }
        }

        return [
            $tokens === [] ? null : implode(' ', $tokens),
            $starts === [] ? null : min($starts),
            $ends === [] ? null : max($ends),
        ];
    }

    private function finalProviderItemId(
        ?string $botId,
        ?string $participantId,
        ?int $startOffsetMs,
        ?int $endOffsetMs,
        ?string $text,
    ): string {
        try {
            $encoded = json_encode([
                'bot_id' => $botId,
                'participant_id' => $participantId,
                'start_offset_ms' => $startOffsetMs,
                'end_offset_ms' => $endOffsetMs,
                'text' => $text,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            $encoded = '[]';
        }

        return 'recall_'.hash('sha256', $encoded);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function participantData(array $data): array
    {
        return is_array($data['participant'] ?? null) ? $data['participant'] : [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function eventData(array $data): array
    {
        return is_array($data['data'] ?? null) ? $data['data'] : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function botId(array $data): ?string
    {
        return $this->stringAt($data, 'bot.id') ?? $this->stringValue($data['bot_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(
        string $eventType,
        ?string $artifactId,
        ?string $utteranceKey,
        ?string $participantId,
        ?string $displayName,
        ?bool $isHost,
        bool $isBot,
        ?string $text,
        ?int $startOffsetMs,
        ?int $endOffsetMs,
        ?MeetingCaptureStatus $captureStatus,
        ?string $failureCode,
        ?string $failureMessage,
        bool $isFinal,
        bool $isPartial,
        bool $isEmptyFinal,
    ): array {
        return [
            'event_type' => $eventType,
            'artifact_id' => $artifactId,
            'utterance_key' => $utteranceKey,
            'provider_participant_id' => $participantId,
            'display_name' => $displayName,
            'is_host' => $isHost,
            'is_bot' => $isBot,
            'text' => $text,
            'start_offset_ms' => $startOffsetMs,
            'end_offset_ms' => $endOffsetMs,
            'capture_status' => $captureStatus?->value,
            'failure_code' => $failureCode,
            'failure_message' => $failureMessage,
            'is_final_transcript' => $isFinal,
            'is_partial_transcript' => $isPartial,
            'is_empty_final' => $isEmptyFinal,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function occurredAt(array $data, string $path): ?CarbonImmutable
    {
        $value = $this->valueAt($data, $path);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function offsetMilliseconds(mixed $seconds): ?int
    {
        if (! is_numeric($seconds)) {
            return null;
        }

        $milliseconds = (int) round((float) $seconds * 1000);

        return $milliseconds >= 0 ? $milliseconds : null;
    }

    private function relativeSeconds(mixed $timestamp): mixed
    {
        return is_array($timestamp) ? ($timestamp['relative'] ?? null) : null;
    }

    private function normalizedText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = preg_replace('/\s+/', ' ', trim($value));

        return $text === '' ? null : $text;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function identifier(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return $this->stringValue($value);
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null;
    }

    private function boolean(mixed $value): bool
    {
        return $this->nullableBoolean($value) ?? false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function stringAt(array $data, string $path): ?string
    {
        return $this->stringValue($this->valueAt($data, $path));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function valueAt(array $data, string $path): mixed
    {
        $value = $data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
