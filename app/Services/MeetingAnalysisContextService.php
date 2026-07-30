<?php

namespace App\Services;

use App\Enums\MeetingProvider;
use App\Enums\SalesRole;
use App\Models\ConversationTranscript;
use App\Models\MeetingAnalysisDelivery;
use App\Models\MeetingCaptureSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class MeetingAnalysisContextService
{
    private const CONTEXT_LIMIT = 8;

    public function constrainEligible(Builder $query, string $table): Builder
    {
        return $query
            ->where("{$table}.status", 'final')
            ->where("{$table}.provider", MeetingProvider::Recall->value)
            ->whereNotNull("{$table}.provider_item_id")
            ->whereIn("{$table}.speaker", [
                SalesRole::Salesperson->value,
                SalesRole::Customer->value,
            ]);
    }

    public function orderChronologically(Builder $query, string $table): Builder
    {
        return $query
            ->orderByRaw("{$table}.started_offset_ms IS NULL")
            ->orderBy("{$table}.started_offset_ms")
            ->orderBy("{$table}.order_index")
            ->orderBy("{$table}.id");
    }

    /**
     * @return Collection<int, ConversationTranscript>
     */
    public function orderedTranscripts(
        MeetingCaptureSession $capture,
        ?int $throughCursor = null,
    ): Collection {
        $query = ConversationTranscript::query()
            ->with('participant')
            ->where('meeting_capture_session_id', $capture->id)
            ->where('session_id', $capture->conversation_session_id);

        $this->constrainEligible($query, 'conversation_transcripts');

        if ($throughCursor !== null) {
            $query->where('conversation_transcripts.order_index', '<=', $throughCursor);
        }

        return $this->orderChronologically($query, 'conversation_transcripts')->get();
    }

    /**
     * @param  Collection<int, ConversationTranscript>  $ordered
     * @return Collection<int, ConversationTranscript>
     */
    public function priorTranscripts(
        MeetingAnalysisDelivery $delivery,
        Collection $ordered,
    ): Collection {
        $transcriptId = $delivery->conversation_transcript_id;
        $position = $ordered->search(
            fn (ConversationTranscript $candidate): bool => $candidate->id === $transcriptId,
        );

        return $position === false
            ? collect()
            : $ordered->take($position)->take(-self::CONTEXT_LIMIT)->values();
    }

    /**
     * @param  Collection<int, ConversationTranscript>  $ordered
     * @return array{
     *     current_provider_item_id: string,
     *     prior_provider_item_ids: array<int, string>
     * }|null
     */
    public function snapshot(
        MeetingAnalysisDelivery $delivery,
        Collection $ordered,
    ): ?array {
        $delivery->loadMissing('transcript');
        $currentItemId = $delivery->transcript?->provider_item_id;

        if (! is_string($currentItemId) || trim($currentItemId) === '') {
            return null;
        }

        return [
            'current_provider_item_id' => $currentItemId,
            'prior_provider_item_ids' => $this->priorTranscripts($delivery, $ordered)
                ->pluck('provider_item_id')
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, ConversationTranscript>|null
     */
    public function resolveSnapshotTranscripts(
        MeetingAnalysisDelivery $delivery,
        mixed $snapshot,
    ): ?Collection {
        if (! is_array($snapshot)) {
            return null;
        }

        $expectedKeys = [
            'current_provider_item_id',
            'prior_provider_item_ids',
        ];
        $actualKeys = array_keys($snapshot);
        sort($expectedKeys);
        sort($actualKeys);

        if ($actualKeys !== $expectedKeys) {
            return null;
        }

        $currentItemId = $snapshot['current_provider_item_id'];
        $priorItemIds = $snapshot['prior_provider_item_ids'];

        if (
            ! is_string($currentItemId)
            || trim($currentItemId) === ''
            || ! is_array($priorItemIds)
            || ! array_is_list($priorItemIds)
            || count($priorItemIds) > self::CONTEXT_LIMIT
        ) {
            return null;
        }

        foreach ($priorItemIds as $priorItemId) {
            if (! is_string($priorItemId) || trim($priorItemId) === '') {
                return null;
            }
        }

        $itemIds = [...$priorItemIds, $currentItemId];

        if (count(array_unique($itemIds, SORT_STRING)) !== count($itemIds)) {
            return null;
        }

        $capture = MeetingCaptureSession::query()->find($delivery->meeting_capture_session_id);

        if (! $capture) {
            return null;
        }

        $transcripts = ConversationTranscript::query()
            ->with('participant')
            ->where('meeting_capture_session_id', $capture->id)
            ->where('session_id', $capture->conversation_session_id)
            ->where('provider', MeetingProvider::Recall->value)
            ->where('status', 'final')
            ->whereIn('speaker', [
                SalesRole::Salesperson->value,
                SalesRole::Customer->value,
            ])
            ->whereIn('provider_item_id', $itemIds)
            ->get();

        if ($transcripts->count() !== count($itemIds)) {
            return null;
        }

        $transcriptsByItemId = $transcripts->keyBy('provider_item_id');
        $ordered = collect($itemIds)
            ->map(fn (string $itemId) => $transcriptsByItemId->get($itemId));

        if ($ordered->contains(null)) {
            return null;
        }

        $currentTranscript = $ordered->last();

        if (
            ! $currentTranscript instanceof ConversationTranscript
            || $currentTranscript->id !== $delivery->conversation_transcript_id
            || $currentTranscript->provider_item_id !== $currentItemId
        ) {
            return null;
        }

        return $ordered->values();
    }

    /**
     * @return Collection<int, string>|null
     */
    public function snapshotEvidenceItemIds(MeetingAnalysisDelivery $delivery): ?Collection
    {
        $transcripts = $this->resolveSnapshotTranscripts(
            $delivery,
            $delivery->evidence_snapshot,
        );

        return $transcripts?->pluck('provider_item_id')->values();
    }

    /**
     * @return Collection<int, ConversationTranscript>|null
     */
    public function snapshotPriorTranscripts(MeetingAnalysisDelivery $delivery): ?Collection
    {
        return $this->resolveSnapshotTranscripts(
            $delivery,
            $delivery->evidence_snapshot,
        )?->slice(0, -1)->values();
    }
}
