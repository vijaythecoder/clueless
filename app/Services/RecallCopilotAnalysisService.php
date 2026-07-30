<?php

namespace App\Services;

use App\Enums\AnalysisDeliveryStatus;
use App\Models\ConversationInsight;
use App\Models\MeetingAnalysisDelivery;
use App\Models\MeetingCaptureSession;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class RecallCopilotAnalysisService
{
    public function __construct(
        private readonly OpenAIResponsesService $responses,
        private readonly MeetingAnalysisDeliveryService $deliveries,
        private readonly MeetingAnalysisContextService $analysisContext,
        private readonly ConversationPersistenceService $persistence,
    ) {}

    /**
     * @return array{response_id: string|null, status: string, ui_tools: array<int, array<string, mixed>>}
     */
    public function analyze(
        MeetingCaptureSession $capture,
        MeetingAnalysisDelivery $delivery,
        string $leaseToken,
    ): array {
        if ($delivery->meeting_capture_session_id !== $capture->id) {
            throw new NotFoundHttpException;
        }

        if ($delivery->status === AnalysisDeliveryStatus::Completed) {
            if (
                ! is_string($delivery->completed_lease_token_hash)
                || ! hash_equals($delivery->completed_lease_token_hash, hash('sha256', $leaseToken))
            ) {
                throw new ConflictHttpException('The analysis lease is no longer active.');
            }

            return $this->replay($capture, $delivery);
        }

        if (
            $delivery->status !== AnalysisDeliveryStatus::Processing
            || ! is_string($delivery->lease_token)
            || ! hash_equals($delivery->lease_token, $leaseToken)
        ) {
            throw new ConflictHttpException('The analysis lease is no longer active.');
        }

        try {
            $result = $this->responses->analyze($delivery);
            $uiTools = $this->persistToolCalls(
                $capture,
                $delivery,
                $result,
                $result['tool_calls'],
            );
            $this->deliveries->acknowledge(
                $capture,
                $delivery,
                $leaseToken,
                AnalysisDeliveryStatus::Completed,
                null,
            );

            return [
                'response_id' => $result['response_id'],
                'status' => AnalysisDeliveryStatus::Completed->value,
                'ui_tools' => $uiTools,
            ];
        } catch (Throwable $exception) {
            $delivery->refresh();

            if (
                $delivery->status === AnalysisDeliveryStatus::Processing
                && is_string($delivery->lease_token)
                && hash_equals($delivery->lease_token, $leaseToken)
            ) {
                if ($exception instanceof ValidationException) {
                    $this->deliveries->acknowledge(
                        $capture,
                        $delivery,
                        $leaseToken,
                        AnalysisDeliveryStatus::Failed,
                        'responses_invalid_tool_call',
                    );
                } else {
                    $this->deliveries->releaseForRetry(
                        $capture,
                        $delivery,
                        $leaseToken,
                        'responses_analysis_failed',
                    );
                }
            }

            throw $exception;
        }
    }

    /**
     * @param  array<int, array{name: string, call_id: string, arguments: array<string, mixed>}>  $calls
     * @return array<int, array<string, mixed>>
     */
    private function persistToolCalls(
        MeetingCaptureSession $capture,
        MeetingAnalysisDelivery $delivery,
        array $response,
        array $calls,
    ): array {
        $session = $capture->conversation()->firstOrFail();
        $allowedEvidenceIds = $this->analysisContext
            ->snapshotEvidenceItemIds($delivery)
            ?->all();

        if ($allowedEvidenceIds === null || $allowedEvidenceIds === []) {
            throw ValidationException::withMessages([
                'evidence_item_ids' => 'The analysis evidence snapshot is unavailable.',
            ]);
        }

        $uiTools = [];
        $insights = [];
        $talkTrackAccepted = false;

        foreach ($calls as $call) {
            if ($call['name'] === 'suggest_talk_track') {
                if ($talkTrackAccepted) {
                    continue;
                }
                $talkTrackAccepted = true;
            }

            $normalized = $this->normalizeToolCall(
                $delivery,
                $response,
                $call,
                $allowedEvidenceIds,
            );
            $insights[] = $normalized['insight'];
            $uiTools[] = $normalized['ui_tool'];
        }

        if ($insights !== []) {
            $this->persistence->persistInsights($session, $insights);
        }

        return $uiTools;
    }

    /**
     * @param  array{name: string, call_id: string, arguments: array<string, mixed>}  $call
     * @param  array<int, string>  $allowedEvidenceIds
     * @return array{insight: array<string, mixed>, ui_tool: array<string, mixed>}
     */
    private function normalizeToolCall(
        MeetingAnalysisDelivery $delivery,
        array $response,
        array $call,
        array $allowedEvidenceIds,
    ): array {
        $name = trim($call['name']);
        $callId = trim($call['call_id']);

        if ($callId === '' || strlen($callId) > 255) {
            throw ValidationException::withMessages([
                'tool_call_id' => 'The analysis tool call ID is invalid.',
            ]);
        }

        $args = match ($name) {
            'show_knowledge_card' => $this->validatedArgs($call['arguments'], [
                'title' => ['required', 'string', 'max:255', 'regex:/\S/'],
                'content' => ['required', 'string', 'max:10000', 'regex:/\S/'],
                'source' => ['present', 'nullable', 'string', 'max:255'],
                'confidence' => ['present', 'nullable', 'numeric', 'between:0,1'],
            ]),
            'suggest_talk_track' => $this->validatedArgs($call['arguments'], [
                'text' => ['required', 'string', 'max:5000', 'regex:/\S/'],
                'reason' => ['present', 'nullable', 'string', 'max:1000'],
                'priority' => ['present', 'nullable', 'in:low,medium,high'],
            ]),
            'capture_objection' => $this->validatedArgs($call['arguments'], [
                'text' => ['required', 'string', 'max:5000', 'regex:/\S/'],
                'category' => ['present', 'nullable', 'string', 'max:255'],
                'severity' => ['present', 'nullable', 'in:low,medium,high'],
            ]),
            'capture_commitment' => $this->validatedArgs($call['arguments'], [
                'speaker' => ['required', 'in:salesperson,customer'],
                'text' => ['required', 'string', 'max:5000', 'regex:/\S/'],
                'deadline' => ['present', 'nullable', 'string', 'max:255'],
            ]),
            'create_follow_up' => $this->validatedArgs($call['arguments'], [
                'text' => ['required', 'string', 'max:5000', 'regex:/\S/'],
                'owner' => ['present', 'nullable', 'in:salesperson,customer,both'],
                'deadline' => ['present', 'nullable', 'string', 'max:255'],
            ]),
            'update_customer_intelligence' => $this->validatedArgs($call['arguments'], [
                'intent' => ['present', 'nullable', 'in:research,evaluation,decision,implementation,unknown'],
                'buyingStage' => ['present', 'nullable', 'string', 'max:255'],
                'sentiment' => ['present', 'nullable', 'in:positive,negative,neutral'],
                'engagementLevel' => ['present', 'nullable', 'numeric', 'between:0,100'],
            ]),
            'capture_pain_point' => $this->validatedEvidenceArgs($call['arguments'], [
                'text' => ['required', 'string', 'max:5000', 'regex:/\S/'],
                'category' => ['present', 'nullable', 'string', 'max:255'],
                'severity' => ['required', 'in:high,medium,low'],
            ], $allowedEvidenceIds),
            'capture_discussion_topic' => $this->validatedEvidenceArgs($call['arguments'], [
                'name' => ['required', 'string', 'max:255', 'regex:/\S/'],
                'sentiment' => ['required', 'in:positive,negative,neutral,mixed'],
                'context' => ['required', 'string', 'max:5000', 'regex:/\S/'],
            ], $allowedEvidenceIds),
            default => throw ValidationException::withMessages([
                'tool' => 'The analysis returned an unsupported UI tool.',
            ]),
        };

        $insight = $this->insightPayload($name, $callId, $args, $delivery, $response);
        $evidenceIds = in_array($name, ['capture_pain_point', 'capture_discussion_topic'], true)
            ? $args['evidence_item_ids']
            : $allowedEvidenceIds;

        return [
            'insight' => $insight,
            'ui_tool' => [
                'name' => $name,
                'call_id' => $callId,
                'arguments' => $args,
                'context' => [
                    'analysisDeliveryId' => $delivery->id,
                    'evidenceItemIds' => $evidenceIds,
                    'evidenceItemDeliveryIds' => array_fill_keys($evidenceIds, $delivery->id),
                ],
            ],
        ];
    }

    private function validatedArgs(array $arguments, array $rules): array
    {
        $expectedKeys = array_values(array_filter(
            array_keys($rules),
            fn (string $key): bool => ! str_contains($key, '.'),
        ));
        $actualKeys = array_keys($arguments);
        sort($expectedKeys);
        sort($actualKeys);

        if ($actualKeys !== $expectedKeys) {
            throw ValidationException::withMessages([
                'arguments' => 'The analysis tool arguments contain unexpected or missing fields.',
            ]);
        }

        return Validator::make($arguments, $rules)->validate();
    }

    private function validatedEvidenceArgs(
        array $arguments,
        array $rules,
        array $allowedEvidenceIds,
    ): array {
        $args = $this->validatedArgs($arguments, [
            ...$rules,
            'evidence_item_ids' => ['required', 'array', 'min:1', 'max:9'],
            'evidence_item_ids.*' => ['string', 'max:255', 'regex:/\S/'],
        ]);
        $evidenceIds = collect($args['evidence_item_ids'])
            ->map(fn (string $itemId): string => trim($itemId))
            ->unique()
            ->values()
            ->all();

        if (count($evidenceIds) !== count($args['evidence_item_ids'])) {
            throw ValidationException::withMessages([
                'evidence_item_ids' => 'Evidence item IDs must be unique.',
            ]);
        }

        if (collect($evidenceIds)->diff($allowedEvidenceIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'evidence_item_ids' => 'The analysis referenced evidence outside the immutable snapshot.',
            ]);
        }

        $args['evidence_item_ids'] = $evidenceIds;

        return $args;
    }

    private function insightPayload(
        string $name,
        string $callId,
        array $args,
        MeetingAnalysisDelivery $delivery,
        array $response,
    ): array {
        [$insightType, $cardType] = match ($name) {
            'show_knowledge_card' => ['knowledge_card', 'knowledge_card'],
            'suggest_talk_track' => ['talk_track', 'talk_track'],
            'capture_objection' => ['objection', 'objection'],
            'capture_commitment' => ['commitment', 'commitment'],
            'create_follow_up' => ['action_item', 'action_item'],
            'update_customer_intelligence' => ['customer_intelligence', 'customer_intelligence'],
            'capture_pain_point' => ['pain_point', 'pain_point'],
            'capture_discussion_topic' => ['discussion_topic', 'discussion_topic'],
        };
        $data = match ($name) {
            'capture_pain_point' => [
                'text' => $args['text'],
                'category' => $args['category'],
                'severity' => $args['severity'],
            ],
            'capture_discussion_topic' => [
                'name' => $args['name'],
                'sentiment' => $args['sentiment'],
                'context' => $args['context'],
            ],
            default => $args,
        };

        return [
            'insight_type' => $insightType,
            'tool_call_id' => $callId,
            'card_type' => $cardType,
            'data' => $data,
            'metadata' => [
                'analysis_delivery_id' => $delivery->id,
                'openai_response_id' => $response['response_id'],
                'openai_model' => $response['model'],
                'openai_latency_ms' => $response['latency_ms'],
                'openai_usage' => $response['usage'],
                'provider_item_id' => $delivery->transcript?->provider_item_id,
                'analysis_driver' => 'responses',
            ],
            ...(in_array($name, ['capture_pain_point', 'capture_discussion_topic'], true)
                ? [
                    'analysis_delivery_id' => $delivery->id,
                    'evidence_item_ids' => $args['evidence_item_ids'],
                ]
                : []),
            'captured_at' => now()->getTimestampMs(),
        ];
    }

    private function replay(
        MeetingCaptureSession $capture,
        MeetingAnalysisDelivery $delivery,
    ): array {
        $insights = ConversationInsight::query()
            ->where('session_id', $capture->conversation_session_id)
            ->get()
            ->filter(fn (ConversationInsight $insight): bool => ($insight->metadata['analysis_delivery_id'] ?? null) === $delivery->id
                && ($insight->metadata['analysis_driver'] ?? null) === 'responses'
            );
        $allowedEvidenceIds = $this->analysisContext
            ->snapshotEvidenceItemIds($delivery)
            ?->all() ?? [];

        return [
            'response_id' => $insights->first()?->metadata['openai_response_id'] ?? null,
            'status' => AnalysisDeliveryStatus::Completed->value,
            'ui_tools' => $insights
                ->map(fn (ConversationInsight $insight): array => $this->uiToolFromInsight(
                    $insight,
                    $delivery,
                    $allowedEvidenceIds,
                ))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int, string>|null  $allowedEvidenceIds
     * @return array<string, mixed>
     */
    public function uiToolFromInsight(
        ConversationInsight $insight,
        MeetingAnalysisDelivery $delivery,
        ?array $allowedEvidenceIds = null,
    ): array {
        $allowedEvidenceIds ??= $this->analysisContext
            ->snapshotEvidenceItemIds($delivery)
            ?->all() ?? [];
        $evidenceIds = $insight->metadata['evidence_item_ids'] ?? $allowedEvidenceIds;

        return [
            'name' => $this->toolNameForCard((string) $insight->card_type),
            'call_id' => $insight->tool_call_id,
            'arguments' => [
                ...$insight->data,
                ...(in_array($insight->card_type, ['pain_point', 'discussion_topic'], true)
                    ? ['evidence_item_ids' => $evidenceIds]
                    : []),
            ],
            'context' => [
                'analysisDeliveryId' => $delivery->id,
                'evidenceItemIds' => $evidenceIds,
                'evidenceItemDeliveryIds' => array_fill_keys($evidenceIds, $delivery->id),
            ],
        ];
    }

    private function toolNameForCard(string $cardType): string
    {
        return match ($cardType) {
            'knowledge_card' => 'show_knowledge_card',
            'talk_track' => 'suggest_talk_track',
            'objection' => 'capture_objection',
            'commitment' => 'capture_commitment',
            'action_item' => 'create_follow_up',
            'customer_intelligence' => 'update_customer_intelligence',
            'pain_point' => 'capture_pain_point',
            'discussion_topic' => 'capture_discussion_topic',
            default => throw new \UnexpectedValueException('Unsupported persisted copilot card.'),
        };
    }
}
