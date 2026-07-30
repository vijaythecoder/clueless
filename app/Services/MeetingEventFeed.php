<?php

namespace App\Services;

use App\Enums\MeetingCaptureStatus;
use App\Models\ConversationTranscript;
use App\Models\MeetingCaptureSession;
use App\Models\MeetingParticipant;
use App\Models\ProviderEvent;
use Illuminate\Support\Collection;

final class MeetingEventFeed
{
    public function __construct(
        private readonly CaptureFailurePresenter $failurePresenter,
    ) {}

    /**
     * @return array{
     *     events: array<int, array<string, mixed>>,
     *     next_cursor: int,
     *     has_more: bool,
     *     capture: array<string, mixed>
     * }
     */
    public function page(MeetingCaptureSession $capture, int $after, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $records = $capture->events()
            ->with('participant')
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $records->count() > $limit;
        $page = $records->take($limit);
        $transcripts = ConversationTranscript::query()
            ->where('meeting_capture_session_id', $capture->id)
            ->whereIn('order_index', $page->pluck('id'))
            ->get()
            ->keyBy('order_index');

        return [
            'events' => $page
                ->map(fn (ProviderEvent $event): array => $this->serializeEvent(
                    $event,
                    $capture,
                    $transcripts,
                ))
                ->values()
                ->all(),
            'next_cursor' => (int) ($page->last()?->id ?? $after),
            'has_more' => $hasMore,
            'capture' => $this->serializeCaptureState($capture->fresh()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeParticipant(MeetingParticipant $participant): array
    {
        return [
            'id' => $participant->id,
            'provider_participant_id' => $participant->provider_participant_id,
            'display_name' => $participant->display_name ?: 'Participant',
            'is_host' => $participant->is_host,
            'is_bot' => $participant->is_bot,
            'sales_role' => $participant->sales_role->value,
            'email_present' => filled($participant->email),
        ];
    }

    /**
     * @param  Collection<int|string, ConversationTranscript>  $transcripts
     * @return array<string, mixed>
     */
    private function serializeEvent(
        ProviderEvent $event,
        MeetingCaptureSession $capture,
        Collection $transcripts,
    ): array {
        $participant = $event->participant;
        $payload = $event->payload;

        if (str_starts_with($event->provider_event_type, 'participant.')) {
            if ($participant === null || $participant->id < 1) {
                return $this->statusNoop($event, $capture);
            }

            return [
                'type' => 'participant.upsert',
                'cursor' => $event->id,
                'participant' => $this->serializeParticipant($participant),
            ];
        }

        if (in_array($event->provider_event_type, ['transcript.partial', 'transcript.final'], true)) {
            $isFinal = $event->provider_event_type === 'transcript.final';
            /** @var ConversationTranscript|null $transcript */
            $transcript = $transcripts->get($event->id);
            $providerItemId = $isFinal ? $transcript?->provider_item_id : null;
            $text = $transcript?->text ?? ($payload['text'] ?? null);
            $utteranceKey = $payload['utterance_key'] ?? null;
            $startedAt = $transcript?->started_offset_ms ?? ($payload['started_offset_ms'] ?? null);

            if (
                ($payload['is_empty_final'] ?? false)
                || ($payload['is_ignored'] ?? false)
                || $participant === null
                || $participant->id < 1
                || ! is_string($text)
                || trim($text) === ''
                || ! is_string($utteranceKey)
                || trim($utteranceKey) === ''
                || ! is_int($startedAt)
                || $startedAt < 0
                || ($isFinal && (! is_string($providerItemId) || trim($providerItemId) === ''))
            ) {
                return $this->statusNoop($event, $capture);
            }

            return [
                'type' => $event->provider_event_type,
                'cursor' => $event->id,
                'turn' => [
                    'event_id' => $event->id,
                    'provider_item_id' => $providerItemId,
                    'utterance_key' => $utteranceKey,
                    'participant_id' => $participant->id,
                    'display_name' => $participant->display_name ?: 'Participant',
                    'sales_role' => $participant->sales_role->value,
                    'text' => $text,
                    'started_at' => $startedAt,
                    'ended_at' => $transcript?->ended_offset_ms ?? ($payload['ended_offset_ms'] ?? null),
                    'status' => $isFinal ? 'final' : 'partial',
                ],
            ];
        }

        $status = $payload['capture_status'] ?? $capture->status->value;
        $failureCode = $payload['failure_code'] ?? $capture->failure_code;

        if ($status === MeetingCaptureStatus::Failed->value || filled($failureCode)) {
            $failure = $this->failurePresenter->present(
                $failureCode,
                $status === MeetingCaptureStatus::Failed->value,
            );

            return [
                'type' => 'capture.error',
                'cursor' => $event->id,
                'code' => $failure['code'],
                'message' => $failure['message'],
            ];
        }

        return [
            'type' => 'capture.status',
            'cursor' => $event->id,
            'status' => $status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statusNoop(ProviderEvent $event, MeetingCaptureSession $capture): array
    {
        return [
            'type' => 'capture.status',
            'cursor' => $event->id,
            'status' => $capture->status->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCaptureState(MeetingCaptureSession $capture): array
    {
        $failure = $this->failurePresenter->forCapture($capture);

        return [
            'id' => $capture->id,
            'status' => $capture->status->value,
            'failure_code' => $failure['code'],
            'failure_message' => $failure['message'],
        ];
    }
}
