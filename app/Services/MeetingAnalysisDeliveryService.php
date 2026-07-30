<?php

namespace App\Services;

use App\Enums\AnalysisDeliveryStatus;
use App\Models\ConversationTranscript;
use App\Models\MeetingAnalysisDelivery;
use App\Models\MeetingCaptureSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MeetingAnalysisDeliveryService
{
    private const CLAIM_LIMIT = 10;

    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private MeetingAnalysisContextService $analysisContext,
    ) {}

    /**
     * @return array{
     *     deliveries: array<int, array<string, mixed>>,
     *     counts: array{pending: int, processing: int, completed: int, failed: int}
     * }
     */
    public function claim(MeetingCaptureSession $capture, int $throughCursor): array
    {
        return DB::transaction(function () use ($capture, $throughCursor): array {
            $cutoff = now()->subSeconds((int) config('services.recall.analysis_lease_seconds', 30));

            $capture->analysisDeliveries()
                ->where('status', AnalysisDeliveryStatus::Processing->value)
                ->where('attempts', '>=', 3)
                ->where('leased_at', '<=', $cutoff)
                ->update([
                    'status' => AnalysisDeliveryStatus::Failed->value,
                    'lease_token' => null,
                    'leased_at' => null,
                    'last_error' => 'analysis_attempts_exhausted',
                    'updated_at' => now(),
                ]);

            $deliveryQuery = MeetingAnalysisDelivery::query()
                ->select('meeting_analysis_deliveries.*')
                ->join(
                    'conversation_transcripts',
                    'conversation_transcripts.id',
                    '=',
                    'meeting_analysis_deliveries.conversation_transcript_id',
                )
                ->where('meeting_analysis_deliveries.meeting_capture_session_id', $capture->id)
                ->where('conversation_transcripts.order_index', '<=', $throughCursor)
                ->where(function ($query) use ($cutoff): void {
                    $query->where('meeting_analysis_deliveries.status', AnalysisDeliveryStatus::Pending->value)
                        ->orWhere(function ($expired) use ($cutoff): void {
                            $expired
                                ->where('meeting_analysis_deliveries.status', AnalysisDeliveryStatus::Processing->value)
                                ->where('meeting_analysis_deliveries.attempts', '<', 3)
                                ->where('meeting_analysis_deliveries.leased_at', '<=', $cutoff);
                        });
                });

            $this->analysisContext->constrainEligible(
                $deliveryQuery,
                'conversation_transcripts',
            );
            $deliveries = $this->analysisContext
                ->orderChronologically($deliveryQuery, 'conversation_transcripts')
                ->limit(self::CLAIM_LIMIT)
                ->get();

            $context = $this->analysisContext->orderedTranscripts($capture, $throughCursor);
            $claimed = [];

            foreach ($deliveries as $delivery) {
                $snapshot = $delivery->evidence_snapshot
                    ?? $this->analysisContext->snapshot($delivery, $context);

                if (
                    $snapshot === null
                    || $this->analysisContext
                        ->resolveSnapshotTranscripts($delivery, $snapshot) === null
                ) {
                    continue;
                }

                $leaseToken = (string) Str::uuid();
                $claimUpdates = [
                    'status' => AnalysisDeliveryStatus::Processing->value,
                    'lease_token' => $leaseToken,
                    'leased_at' => now(),
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error' => null,
                    'updated_at' => now(),
                ];

                if ($delivery->evidence_snapshot === null) {
                    $claimUpdates['evidence_snapshot'] = json_encode(
                        $snapshot,
                        JSON_THROW_ON_ERROR,
                    );
                }

                $updated = MeetingAnalysisDelivery::query()
                    ->whereKey($delivery->id)
                    ->where(function ($query) use ($cutoff): void {
                        $query->where('status', AnalysisDeliveryStatus::Pending->value)
                            ->orWhere(function ($expired) use ($cutoff): void {
                                $expired
                                    ->where('status', AnalysisDeliveryStatus::Processing->value)
                                    ->where('attempts', '<', 3)
                                    ->where('leased_at', '<=', $cutoff);
                            });
                    })
                    ->update($claimUpdates);

                if ($updated !== 1) {
                    continue;
                }

                $delivery->refresh()->load('transcript.participant');
                $serialized = $this->serializeDelivery($delivery, $leaseToken);

                if ($serialized !== null) {
                    $claimed[] = $serialized;
                }
            }

            return [
                'deliveries' => $claimed,
                'counts' => $this->counts($capture),
            ];
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function acknowledge(
        MeetingCaptureSession $capture,
        MeetingAnalysisDelivery $delivery,
        string $leaseToken,
        AnalysisDeliveryStatus $status,
        ?string $errorCode,
    ): MeetingAnalysisDelivery {
        if ($delivery->meeting_capture_session_id !== $capture->id) {
            throw new NotFoundHttpException;
        }

        if ($delivery->status === AnalysisDeliveryStatus::Completed) {
            return $this->completedReplay($delivery, $leaseToken, $status);
        }

        if (
            $delivery->status !== AnalysisDeliveryStatus::Processing
            || ! is_string($delivery->lease_token)
            || ! hash_equals($delivery->lease_token, $leaseToken)
        ) {
            throw new ConflictHttpException('The analysis lease is no longer active.');
        }

        $updated = MeetingAnalysisDelivery::query()
            ->whereKey($delivery->id)
            ->where('meeting_capture_session_id', $capture->id)
            ->where('status', AnalysisDeliveryStatus::Processing->value)
            ->where('lease_token', $leaseToken)
            ->update([
                'status' => $status->value,
                'lease_token' => null,
                'completed_lease_token_hash' => $status === AnalysisDeliveryStatus::Completed
                    ? hash('sha256', $leaseToken)
                    : null,
                'leased_at' => null,
                'last_error' => $status === AnalysisDeliveryStatus::Failed
                    ? $this->sanitizeErrorCode($errorCode)
                    : null,
                'completed_at' => $status === AnalysisDeliveryStatus::Completed ? now() : null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            $delivery->refresh();

            if ($delivery->status === AnalysisDeliveryStatus::Completed) {
                return $this->completedReplay($delivery, $leaseToken, $status);
            }

            throw new ConflictHttpException('The analysis lease is no longer active.');
        }

        return $delivery->fresh();
    }

    public function releaseForRetry(
        MeetingCaptureSession $capture,
        MeetingAnalysisDelivery $delivery,
        string $leaseToken,
        string $errorCode,
    ): MeetingAnalysisDelivery {
        if ($delivery->meeting_capture_session_id !== $capture->id) {
            throw new NotFoundHttpException;
        }

        if (
            $delivery->status !== AnalysisDeliveryStatus::Processing
            || ! is_string($delivery->lease_token)
            || ! hash_equals($delivery->lease_token, $leaseToken)
        ) {
            throw new ConflictHttpException('The analysis lease is no longer active.');
        }

        $status = $delivery->attempts >= 3
            ? AnalysisDeliveryStatus::Failed
            : AnalysisDeliveryStatus::Pending;
        $updated = MeetingAnalysisDelivery::query()
            ->whereKey($delivery->id)
            ->where('meeting_capture_session_id', $capture->id)
            ->where('status', AnalysisDeliveryStatus::Processing->value)
            ->where('lease_token', $leaseToken)
            ->update([
                'status' => $status->value,
                'lease_token' => null,
                'leased_at' => null,
                'last_error' => $this->sanitizeErrorCode($errorCode),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new ConflictHttpException('The analysis lease is no longer active.');
        }

        return $delivery->fresh();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeDelivery(
        MeetingAnalysisDelivery $delivery,
        string $leaseToken,
    ): ?array {
        $snapshotTranscripts = $this->analysisContext->resolveSnapshotTranscripts(
            $delivery,
            $delivery->evidence_snapshot,
        );

        if ($snapshotTranscripts === null) {
            return null;
        }

        $transcript = $snapshotTranscripts->last();
        $prior = $snapshotTranscripts->slice(0, -1);

        if (! $transcript instanceof ConversationTranscript) {
            return null;
        }

        return [
            'id' => $delivery->id,
            'lease_token' => $leaseToken,
            ...$this->serializeTranscript($transcript),
            'context' => $prior
                ->map(fn (ConversationTranscript $item): array => $this->serializeTranscript($item))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTranscript(ConversationTranscript $transcript): array
    {
        return [
            'provider_item_id' => $transcript->provider_item_id,
            'participant_id' => $transcript->meeting_participant_id,
            'display_name' => $transcript->participant?->display_name ?: 'Participant',
            'sales_role' => $transcript->speaker,
            'text' => $transcript->text,
            'started_at' => $transcript->started_offset_ms ?? 0,
            'ended_at' => $transcript->ended_offset_ms,
            'started_offset_ms' => $transcript->started_offset_ms,
            'ended_offset_ms' => $transcript->ended_offset_ms,
        ];
    }

    /**
     * @return array{pending: int, processing: int, completed: int, failed: int}
     */
    private function counts(MeetingCaptureSession $capture): array
    {
        $counts = $capture->analysisDeliveries()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'pending' => (int) ($counts[AnalysisDeliveryStatus::Pending->value] ?? 0),
            'processing' => (int) ($counts[AnalysisDeliveryStatus::Processing->value] ?? 0),
            'completed' => (int) ($counts[AnalysisDeliveryStatus::Completed->value] ?? 0),
            'failed' => (int) ($counts[AnalysisDeliveryStatus::Failed->value] ?? 0),
        ];
    }

    private function sanitizeErrorCode(?string $errorCode): string
    {
        $normalized = Str::lower(trim((string) $errorCode));
        $normalized = preg_replace('/[^a-z0-9._-]+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '._-');

        return Str::limit($normalized !== '' ? $normalized : 'analysis_failed', 100, '');
    }

    private function completedReplay(
        MeetingAnalysisDelivery $delivery,
        string $leaseToken,
        AnalysisDeliveryStatus $requestedStatus,
    ): MeetingAnalysisDelivery {
        $tokenHash = hash('sha256', $leaseToken);

        if (
            $requestedStatus !== AnalysisDeliveryStatus::Completed
            || ! is_string($delivery->completed_lease_token_hash)
            || ! hash_equals($delivery->completed_lease_token_hash, $tokenHash)
        ) {
            throw new ConflictHttpException('The analysis lease is no longer active.');
        }

        return $delivery;
    }
}
