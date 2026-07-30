<?php

namespace App\Services\Recall;

use App\Data\NormalizedProviderEvent;
use App\Enums\AnalysisDeliveryStatus;
use App\Enums\MeetingCaptureStatus;
use App\Enums\MeetingProvider;
use App\Enums\SalesRole;
use App\Jobs\AnalyzeRecallCapture;
use App\Models\ConversationTranscript;
use App\Models\MeetingAnalysisDelivery;
use App\Models\MeetingCaptureSession;
use App\Models\MeetingParticipant;
use App\Models\ProviderEvent;
use App\Services\ConversationPersistenceService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RecallWebhookService
{
    public function __construct(
        private readonly ConversationPersistenceService $conversationPersistenceService,
    ) {}

    public function persist(NormalizedProviderEvent $normalized, string $webhookId): void
    {
        if ($normalized->providerBotId === null || trim($normalized->providerBotId) === '') {
            throw new NotFoundHttpException;
        }

        DB::transaction(function () use ($normalized, $webhookId): void {
            $capture = MeetingCaptureSession::query()
                ->where('provider', MeetingProvider::Recall->value)
                ->where('provider_bot_id', $normalized->providerBotId)
                ->first();

            if ($capture === null) {
                throw new NotFoundHttpException;
            }

            $providerEvent = ProviderEvent::query()->createOrFirst(
                ['provider_webhook_id' => $webhookId],
                [
                    'meeting_capture_session_id' => $capture->id,
                    'provider' => MeetingProvider::Recall,
                    'provider_event_type' => $normalized->eventType,
                    'provider_event_id' => $normalized->providerEventId,
                    'provider_utterance_key' => $normalized->utteranceKey,
                    'payload' => $normalized->payload,
                    'provider_occurred_at' => $normalized->providerOccurredAt,
                    'received_at' => now(),
                ],
            );

            if (! $providerEvent->wasRecentlyCreated) {
                return;
            }

            $eventUpdates = [];
            $payload = $this->payloadForPersistence($capture, $normalized);

            if ($payload !== $normalized->payload) {
                $eventUpdates['payload'] = $payload;
            }

            $participant = $this->upsertParticipant($capture, $normalized);

            if ($participant !== null) {
                $eventUpdates['meeting_participant_id'] = $participant->id;
            }

            if ($eventUpdates !== []) {
                $providerEvent->update($eventUpdates);
            }

            $this->applyLifecycle($capture, $normalized);

            if (! $normalized->isFinalTranscript || $normalized->isEmptyFinal || $normalized->providerItemId === null) {
                return;
            }

            $transcript = ConversationTranscript::query()->firstOrCreate(
                [
                    'session_id' => $capture->conversation_session_id,
                    'provider' => MeetingProvider::Recall->value,
                    'provider_item_id' => $normalized->providerItemId,
                ],
                [
                    'speaker' => $participant?->sales_role?->value ?? SalesRole::Unknown->value,
                    'source_stream' => 'recall',
                    'text' => $normalized->text,
                    'spoken_at' => $this->spokenAt($capture, $normalized),
                    'status' => 'final',
                    'order_index' => $providerEvent->id,
                    'meeting_capture_session_id' => $capture->id,
                    'meeting_participant_id' => $participant?->id,
                    'started_offset_ms' => $normalized->startOffsetMs,
                    'ended_offset_ms' => $normalized->endOffsetMs,
                ],
            );

            if ($transcript->wasRecentlyCreated) {
                MeetingAnalysisDelivery::query()->firstOrCreate(
                    ['conversation_transcript_id' => $transcript->id],
                    [
                        'meeting_capture_session_id' => $capture->id,
                        'status' => AnalysisDeliveryStatus::Pending,
                    ],
                );

                if (config('openai.recall_analysis.driver') === 'responses') {
                    AnalyzeRecallCapture::dispatch($capture->id);
                }
            }

            if ($capture->status === MeetingCaptureStatus::Ended) {
                $this->conversationPersistenceService->finalizeFromCapture($capture);
            }
        });
    }

    private function upsertParticipant(
        MeetingCaptureSession $capture,
        NormalizedProviderEvent $normalized,
    ): ?MeetingParticipant {
        if ($normalized->providerParticipantId === null) {
            return null;
        }

        $attributes = [
            'meeting_capture_session_id' => $capture->id,
            'provider_participant_id' => $normalized->providerParticipantId,
        ];
        $values = $this->participantValues($normalized);
        $participant = MeetingParticipant::query()->createOrFirst($attributes, [
            ...$values,
            'sales_role' => $normalized->isBot ? SalesRole::Bot : SalesRole::Unknown,
        ]);

        if (! $participant->wasRecentlyCreated) {
            $participant->fill($values)->save();
        }

        return $participant;
    }

    /**
     * @return array<string, mixed>
     */
    private function participantValues(NormalizedProviderEvent $normalized): array
    {
        return array_filter([
            'display_name' => $normalized->displayName,
            'email' => $normalized->email,
            'email_hash' => $normalized->email === null
                ? null
                : hash('sha256', strtolower($normalized->email)),
            'is_host' => $normalized->isHost,
            'is_bot' => $normalized->isBot,
        ], fn (mixed $value, string $key): bool => $key === 'is_bot' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadForPersistence(
        MeetingCaptureSession $capture,
        NormalizedProviderEvent $normalized,
    ): array {
        $payload = $normalized->payload;

        if ($normalized->isEmptyFinal) {
            $payload['diagnostic'] = 'empty_final';
        }

        if ($normalized->isPartialTranscript && $normalized->utteranceKey !== null) {
            $hasFinal = ProviderEvent::query()
                ->where('meeting_capture_session_id', $capture->id)
                ->where('provider_event_type', 'transcript.final')
                ->where('provider_utterance_key', $normalized->utteranceKey)
                ->exists();

            if ($hasFinal) {
                $payload['is_ignored'] = true;
            }
        }

        return $payload;
    }

    private function applyLifecycle(MeetingCaptureSession $capture, NormalizedProviderEvent $normalized): void
    {
        if ($normalized->captureStatus === null) {
            return;
        }

        if ($capture->status === MeetingCaptureStatus::Failed
            && $normalized->captureStatus !== MeetingCaptureStatus::Failed) {
            return;
        }

        if ($capture->status === MeetingCaptureStatus::Ended
            && ! in_array(
                $normalized->captureStatus,
                [MeetingCaptureStatus::Ended, MeetingCaptureStatus::Failed],
                true,
            )) {
            return;
        }

        $attributes = [
            'status' => $normalized->captureStatus,
            'failure_code' => $normalized->failureCode,
            'failure_message' => $normalized->failureMessage,
        ];

        if ($normalized->captureStatus === MeetingCaptureStatus::Active && $capture->started_at === null) {
            $attributes['started_at'] = $normalized->providerOccurredAt ?? now();
        }

        if ($normalized->captureStatus === MeetingCaptureStatus::Ended) {
            $attributes['ended_at'] = $capture->ended_at ?? $normalized->providerOccurredAt ?? now();
        }

        $capture->update($attributes);

        if ($capture->status === MeetingCaptureStatus::Ended) {
            $this->conversationPersistenceService->finalizeFromCapture($capture);
        }
    }

    private function spokenAt(MeetingCaptureSession $capture, NormalizedProviderEvent $normalized): \DateTimeInterface
    {
        if ($capture->started_at !== null && $normalized->startOffsetMs !== null) {
            return $capture->started_at->copy()->addMilliseconds($normalized->startOffsetMs);
        }

        return now();
    }
}
