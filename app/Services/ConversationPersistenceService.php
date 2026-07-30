<?php

namespace App\Services;

use App\Enums\MeetingCaptureStatus;
use App\Models\ConversationInsight;
use App\Models\ConversationSession;
use App\Models\ConversationTranscript;
use App\Models\MeetingCaptureSession;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ConversationPersistenceService
{
    private const TRANSACTION_ATTEMPTS = 3;

    private const TRANSACTION_RETRY_DELAY_MICROSECONDS = 75_000;

    public function persistTranscript(ConversationSession $session, array $data): ConversationTranscript
    {
        return DB::transaction(function () use ($session, $data) {
            $this->lockSession($session);
            $attributes = $this->transcriptAttributes($session, $data);

            if ($attributes['openai_item_id']) {
                $existing = ConversationTranscript::query()
                    ->where('session_id', $session->id)
                    ->where('source_stream', $attributes['source_stream'])
                    ->where('openai_item_id', $attributes['openai_item_id'])
                    ->first();

                if ($existing) {
                    $existing->fill(collect($attributes)->except('order_index')->all())->save();

                    return $existing;
                }
            }

            return ConversationTranscript::create($attributes);
        });
    }

    public function persistTranscripts(ConversationSession $session, array $transcripts): void
    {
        DB::transaction(function () use ($session, $transcripts) {
            $this->lockSession($session);

            $identified = [];
            $anonymous = [];
            $nextOrder = ((int) ConversationTranscript::query()
                ->where('session_id', $session->id)
                ->max('order_index')) + 1;

            foreach ($transcripts as $data) {
                $attributes = $this->transcriptAttributes($session, $data, $nextOrder++);
                $row = $this->serializeTranscript($attributes);

                if ($attributes['openai_item_id']) {
                    $identified[] = $row;
                } else {
                    $anonymous[] = $row;
                }
            }

            if ($identified) {
                ConversationTranscript::upsert(
                    $identified,
                    ['session_id', 'source_stream', 'openai_item_id'],
                    [
                        'speaker',
                        'text',
                        'spoken_at',
                        'status',
                        'group_id',
                        'system_category',
                        'metadata',
                    ],
                );
            }

            if ($anonymous) {
                ConversationTranscript::insert($anonymous);
            }
        });
    }

    public function persistInsight(ConversationSession $session, array $data): ConversationInsight
    {
        return $this->transactionWithRetry(function () use ($session, $data) {
            $this->lockSession($session);
            $insight = $this->persistInsightLocked($session, $data);
            $this->refreshFinalizedMetricsLocked($session);

            return $insight;
        });
    }

    public function persistInsights(ConversationSession $session, array $insights): void
    {
        $this->transactionWithRetry(function () use ($session, $insights) {
            $this->lockSession($session);

            foreach ($insights as $data) {
                $this->persistInsightLocked($session, $data);
            }

            $this->refreshFinalizedMetricsLocked($session);
        });
    }

    public function finalize(ConversationSession $session, array $data): void
    {
        $this->finalizeAt($session, $data, now());
    }

    public function finalizeFromCapture(MeetingCaptureSession $capture): void
    {
        if ($capture->status !== MeetingCaptureStatus::Ended || $capture->ended_at === null) {
            return;
        }

        $session = $capture->conversation()->firstOrFail();
        $startedAt = $capture->started_at ?? $session->started_at;
        $durationSeconds = $startedAt === null
            ? 0
            : max(0, (int) $startedAt->diffInSeconds($capture->ended_at, false));

        $this->finalizeAt(
            $session,
            ['duration_seconds' => $durationSeconds],
            $capture->ended_at,
        );
    }

    private function finalizeAt(ConversationSession $session, array $data, DateTimeInterface $endedAt): void
    {
        DB::transaction(function () use ($session, $data, $endedAt) {
            $lockedSession = ConversationSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();
            $isReplay = $lockedSession->ended_at !== null;

            $lockedSession->update([
                'ended_at' => $lockedSession->ended_at ?? $endedAt,
                'duration_seconds' => $isReplay
                    ? max($lockedSession->duration_seconds, $data['duration_seconds'])
                    : $data['duration_seconds'],
                'final_intent' => $this->terminalValue($lockedSession, $data, 'final_intent', $isReplay),
                'final_buying_stage' => $this->terminalValue($lockedSession, $data, 'final_buying_stage', $isReplay),
                'final_engagement_level' => $this->terminalValue(
                    $lockedSession,
                    $data,
                    'final_engagement_level',
                    $isReplay,
                ),
                'final_sentiment' => $this->terminalValue($lockedSession, $data, 'final_sentiment', $isReplay),
                'ai_summary' => $this->terminalValue($lockedSession, $data, 'ai_summary', $isReplay),
                ...$this->metricAttributes($lockedSession),
            ]);

            $session->setRawAttributes($lockedSession->getAttributes(), true);
        });
    }

    private function transcriptAttributes(
        ConversationSession $session,
        array $data,
        ?int $orderIndex = null,
    ): array {
        $openAIItemId = $data['openai_item_id'] ?? null;

        return [
            'session_id' => $session->id,
            'speaker' => $data['speaker'],
            'source_stream' => $data['source_stream'] ?? ($openAIItemId ? $data['speaker'] : null),
            'text' => $data['text'],
            'spoken_at' => Carbon::createFromTimestampMs($data['spoken_at']),
            'openai_item_id' => $openAIItemId,
            'status' => $data['status'] ?? 'final',
            'group_id' => $data['group_id'] ?? null,
            'system_category' => $data['system_category'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'order_index' => $orderIndex ?? (((int) ConversationTranscript::query()
                ->where('session_id', $session->id)
                ->max('order_index')) + 1),
        ];
    }

    private function insightAttributes(ConversationSession $session, array $data): array
    {
        $isEvidenceCard = in_array(
            $data['card_type'] ?? null,
            ['pain_point', 'discussion_topic'],
            true,
        );
        $cardData = $isEvidenceCard
            ? $this->normalizeEvidenceCardData($data['card_type'], $data['data'])
            : $data['data'];
        $metadata = $data['metadata'] ?? null;
        $semanticKey = null;

        if ($isEvidenceCard) {
            $evidenceItemIds = collect($data['evidence_item_ids'])
                ->map(fn (string $itemId): string => trim($itemId))
                ->filter()
                ->unique()
                ->values()
                ->all();
            $metadata = [
                ...($metadata ?? []),
                'analysis_delivery_id' => $data['analysis_delivery_id'],
                'evidence_item_ids' => $evidenceItemIds,
            ];
            $semanticKey = $this->semanticKey(
                $data['analysis_delivery_id'],
                $data['card_type'],
                $evidenceItemIds,
                $cardData,
            );
        }

        return [
            'session_id' => $session->id,
            'insight_type' => $data['insight_type'],
            'tool_call_id' => $data['tool_call_id'] ?? null,
            'card_type' => $data['card_type'] ?? null,
            'approval_status' => $data['approval_status'] ?? null,
            'data' => $cardData,
            'metadata' => $metadata,
            'captured_at' => Carbon::createFromTimestampMs($data['captured_at']),
            'semantic_key' => $semanticKey,
        ];
    }

    private function serializeTranscript(array $attributes): array
    {
        return [
            ...$attributes,
            'metadata' => isset($attributes['metadata']) ? json_encode($attributes['metadata'], JSON_THROW_ON_ERROR) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function persistInsightLocked(
        ConversationSession $session,
        array $data,
    ): ConversationInsight {
        $attributes = $this->insightAttributes($session, $data);

        if ($attributes['semantic_key']) {
            if ($attributes['tool_call_id']) {
                $byToolCall = ConversationInsight::query()
                    ->where('session_id', $session->id)
                    ->where('tool_call_id', $attributes['tool_call_id'])
                    ->first();

                if ($byToolCall) {
                    return $byToolCall;
                }
            }

            $bySemanticKey = ConversationInsight::query()
                ->where('session_id', $session->id)
                ->where('semantic_key', $attributes['semantic_key'])
                ->first();

            if ($bySemanticKey) {
                return $bySemanticKey;
            }

            try {
                return ConversationInsight::create($attributes);
            } catch (QueryException $exception) {
                $winner = ConversationInsight::query()
                    ->where('session_id', $session->id)
                    ->where(function ($query) use ($attributes) {
                        $query->where('semantic_key', $attributes['semantic_key']);

                        if ($attributes['tool_call_id']) {
                            $query->orWhere('tool_call_id', $attributes['tool_call_id']);
                        }
                    })
                    ->first();

                if ($winner) {
                    return $winner;
                }

                throw $exception;
            }
        }

        if ($attributes['tool_call_id']) {
            return ConversationInsight::updateOrCreate(
                [
                    'session_id' => $session->id,
                    'tool_call_id' => $attributes['tool_call_id'],
                ],
                $attributes,
            );
        }

        return ConversationInsight::create($attributes);
    }

    private function normalizeEvidenceCardData(string $cardType, array $data): array
    {
        return match ($cardType) {
            'pain_point' => [
                'text' => Str::squish($data['text']),
                'category' => filled($data['category'] ?? null)
                    ? Str::squish($data['category'])
                    : null,
                'severity' => $data['severity'],
            ],
            'discussion_topic' => [
                'name' => Str::squish($data['name']),
                'sentiment' => $data['sentiment'],
                'context' => Str::squish($data['context']),
            ],
        };
    }

    private function semanticKey(
        int $analysisDeliveryId,
        string $cardType,
        array $evidenceItemIds,
        array $cardData,
    ): string {
        sort($evidenceItemIds, SORT_STRING);

        $canonicalData = collect($cardData)
            ->map(fn ($value) => is_string($value) ? Str::lower(Str::squish($value)) : $value)
            ->sortKeys()
            ->all();

        return hash('sha256', json_encode([
            'analysis_delivery_id' => $analysisDeliveryId,
            'card_type' => $cardType,
            'evidence_item_ids' => $evidenceItemIds,
            'data' => $canonicalData,
        ], JSON_THROW_ON_ERROR));
    }

    private function transactionWithRetry(callable $callback): mixed
    {
        for ($attempt = 1; $attempt <= self::TRANSACTION_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction($callback);
            } catch (Throwable $exception) {
                if (
                    $attempt === self::TRANSACTION_ATTEMPTS
                    || ! $this->isRetryableSqliteContention($exception)
                ) {
                    throw $exception;
                }

                usleep(self::TRANSACTION_RETRY_DELAY_MICROSECONDS * $attempt);
            }
        }

        throw new \LogicException('Transaction retry loop exited unexpectedly.');
    }

    private function isRetryableSqliteContention(Throwable $exception): bool
    {
        if (
            DB::connection()->getDriverName() !== 'sqlite'
            || ! ($exception instanceof QueryException || $exception instanceof DeadlockException)
        ) {
            return false;
        }

        $message = Str::lower($exception->getMessage());

        return str_contains($message, 'database is locked')
            || str_contains($message, 'database table is locked')
            || str_contains($message, 'database is busy');
    }

    private function metricCount(
        ConversationSession $session,
        array $modernCardTypes,
        array $legacyInsightTypes,
    ): int {
        return $session->insights()
            ->where(function ($query) use ($modernCardTypes, $legacyInsightTypes) {
                $query->whereIn('card_type', $modernCardTypes)
                    ->orWhere(function ($query) use ($legacyInsightTypes) {
                        $query->whereNull('card_type')
                            ->whereIn('insight_type', $legacyInsightTypes);
                    });
            })
            ->count();
    }

    /**
     * @return array<string, int>
     */
    private function metricAttributes(ConversationSession $session): array
    {
        return [
            'total_transcripts' => $session->transcripts()->count(),
            'total_insights' => $this->metricCount(
                $session,
                ['knowledge_card', 'talk_track', 'objection', 'customer_intelligence'],
                ['key_insight'],
            ),
            'total_topics' => $this->metricCount(
                $session,
                ['topic', 'discussion_topic', 'local_discussion_topic'],
                ['topic'],
            ),
            'total_commitments' => $this->metricCount($session, ['commitment'], ['commitment']),
            'total_action_items' => $this->metricCount($session, ['action_item'], ['action_item']),
        ];
    }

    private function refreshFinalizedMetricsLocked(ConversationSession $session): void
    {
        $isFinalized = ConversationSession::query()
            ->whereKey($session->id)
            ->whereNotNull('ended_at')
            ->exists();

        if (! $isFinalized) {
            return;
        }

        ConversationSession::query()
            ->whereKey($session->id)
            ->update($this->metricAttributes($session));
    }

    private function lockSession(ConversationSession $session): void
    {
        ConversationSession::query()
            ->whereKey($session->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function terminalValue(
        ConversationSession $session,
        array $data,
        string $attribute,
        bool $isReplay,
    ): mixed {
        if (! array_key_exists($attribute, $data) || $data[$attribute] === null) {
            return $session->getAttribute($attribute);
        }

        if (! $isReplay || $session->getAttribute($attribute) === null) {
            return $data[$attribute];
        }

        return $session->getAttribute($attribute);
    }
}
