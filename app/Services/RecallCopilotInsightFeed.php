<?php

namespace App\Services;

use App\Enums\AnalysisDeliveryStatus;
use App\Models\ConversationInsight;
use App\Models\MeetingCaptureSession;

final class RecallCopilotInsightFeed
{
    public function __construct(
        private readonly RecallCopilotAnalysisService $analysis,
    ) {}

    /**
     * @return array{
     *     ui_tools: array<int, array<string, mixed>>,
     *     next_cursor: int,
     *     has_more: bool,
     *     counts: array{pending: int, processing: int, completed: int, failed: int}
     * }
     */
    public function page(MeetingCaptureSession $capture, int $after, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $records = ConversationInsight::query()
            ->where('session_id', $capture->conversation_session_id)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get()
            ->values();
        $hasMore = $records->count() > $limit;
        $page = $records->take($limit);
        $responseInsights = $page
            ->filter(fn (ConversationInsight $insight): bool => ($insight->metadata['analysis_driver'] ?? null) === 'responses'
                && is_int($insight->metadata['analysis_delivery_id'] ?? null)
            )
            ->values();
        $deliveryIds = $responseInsights
            ->pluck('metadata.analysis_delivery_id')
            ->filter(fn ($id): bool => is_int($id))
            ->unique()
            ->values();
        $deliveries = $capture->analysisDeliveries()
            ->whereIn('id', $deliveryIds)
            ->get()
            ->keyBy('id');
        $counts = $capture->analysisDeliveries()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'ui_tools' => $responseInsights
                ->map(function (ConversationInsight $insight) use ($deliveries): ?array {
                    $deliveryId = $insight->metadata['analysis_delivery_id'];
                    $delivery = $deliveries->get($deliveryId);

                    return $delivery
                        ? $this->analysis->uiToolFromInsight($insight, $delivery)
                        : null;
                })
                ->filter()
                ->values()
                ->all(),
            'next_cursor' => (int) ($page->last()?->id ?? $after),
            'has_more' => $hasMore,
            'counts' => [
                'pending' => (int) ($counts[AnalysisDeliveryStatus::Pending->value] ?? 0),
                'processing' => (int) ($counts[AnalysisDeliveryStatus::Processing->value] ?? 0),
                'completed' => (int) ($counts[AnalysisDeliveryStatus::Completed->value] ?? 0),
                'failed' => (int) ($counts[AnalysisDeliveryStatus::Failed->value] ?? 0),
            ],
        ];
    }
}
