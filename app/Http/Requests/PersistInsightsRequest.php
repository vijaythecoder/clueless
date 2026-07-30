<?php

namespace App\Http\Requests;

use App\Enums\MeetingProvider;
use App\Models\ConversationSession;
use App\Models\MeetingAnalysisDelivery;
use App\Services\MeetingAnalysisContextService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PersistInsightsRequest extends FormRequest
{
    private const MAX_DATA_BYTES = 65_536;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->has('insights')) {
            return [
                'insights' => ['required', 'array', 'min:1', 'max:200'],
                ...$this->insightRules('insights.*.'),
            ];
        }

        return $this->insightRules();
    }

    public function insights(): array
    {
        $validated = $this->validated();

        return $validated['insights'] ?? [$validated];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $insights = $this->has('insights')
                ? $this->input('insights', [])
                : [$this->all()];

            foreach ($insights as $index => $insight) {
                if (! is_array($insight['data'] ?? null)) {
                    continue;
                }

                $encoded = json_encode($insight['data']);

                if ($encoded !== false && strlen($encoded) > self::MAX_DATA_BYTES) {
                    $attribute = $this->has('insights')
                        ? "insights.{$index}.data"
                        : 'data';

                    $validator->errors()->add(
                        $attribute,
                        'The insight data may not exceed 64 KB.',
                    );
                }

                $this->validateEvidenceInsight($validator, $insight, $index);
            }
        });
    }

    private function insightRules(string $prefix = ''): array
    {
        return [
            $prefix.'insight_type' => ['required', 'string', 'max:255'],
            $prefix.'tool_call_id' => ['nullable', 'string', 'max:255'],
            $prefix.'card_type' => ['nullable', 'string', 'max:255'],
            $prefix.'approval_status' => ['nullable', 'string', 'max:255'],
            $prefix.'data' => ['required', 'array'],
            $prefix.'metadata' => ['nullable', 'array'],
            $prefix.'analysis_delivery_id' => ['nullable', 'integer', 'min:1'],
            $prefix.'evidence_item_ids' => ['nullable', 'array', 'min:1', 'max:20'],
            $prefix.'evidence_item_ids.*' => ['string', 'max:255', 'regex:/\S/'],
            $prefix.'captured_at' => ['required', 'integer', 'min:0'],
        ];
    }

    private function validateEvidenceInsight(
        Validator $validator,
        array $insight,
        int $index,
    ): void {
        $cardType = $insight['card_type'] ?? null;

        if (! in_array($cardType, ['pain_point', 'discussion_topic'], true)) {
            return;
        }

        $prefix = $this->has('insights') ? "insights.{$index}." : '';
        $deliveryId = $insight['analysis_delivery_id'] ?? null;
        $evidenceItemIds = $insight['evidence_item_ids'] ?? null;
        $toolCallId = $insight['tool_call_id'] ?? null;

        if (! is_string($toolCallId) || trim($toolCallId) === '') {
            $validator->errors()->add(
                $prefix.'tool_call_id',
                'Evidence-backed insights require a tool call ID.',
            );
        }

        if (! is_int($deliveryId)) {
            $validator->errors()->add(
                $prefix.'analysis_delivery_id',
                'Evidence-backed insights require an analysis delivery.',
            );
        }

        if (! is_array($evidenceItemIds) || $evidenceItemIds === []) {
            $validator->errors()->add(
                $prefix.'evidence_item_ids',
                'Evidence-backed insights require at least one evidence item.',
            );
        }

        $this->validateCardData($validator, $cardType, $insight['data'], $prefix);

        if (! is_int($deliveryId) || ! is_array($evidenceItemIds) || $evidenceItemIds === []) {
            return;
        }

        $session = $this->route('session');

        if (! $session instanceof ConversationSession) {
            return;
        }

        $delivery = MeetingAnalysisDelivery::query()
            ->with('transcript')
            ->whereKey($deliveryId)
            ->whereHas('capture', fn ($query) => $query
                ->where('conversation_session_id', $session->id)
                ->where('provider', MeetingProvider::Recall->value))
            ->first();

        if (! $delivery || ! $this->hasValidDeliveryTranscript($delivery, $session)) {
            $validator->errors()->add(
                $prefix.'analysis_delivery_id',
                'The analysis delivery does not belong to this conversation.',
            );

            return;
        }

        $uniqueEvidenceIds = collect($evidenceItemIds)
            ->filter(fn ($itemId) => is_string($itemId) && trim($itemId) !== '')
            ->unique()
            ->values();

        $allowedEvidenceIds = app(MeetingAnalysisContextService::class)
            ->snapshotEvidenceItemIds($delivery);

        if (
            $allowedEvidenceIds === null
            || $allowedEvidenceIds->isEmpty()
            ||
            ! $allowedEvidenceIds->contains($delivery->transcript->provider_item_id)
            || $uniqueEvidenceIds->diff($allowedEvidenceIds)->isNotEmpty()
        ) {
            $validator->errors()->add(
                $prefix.'evidence_item_ids',
                'Every evidence item must be the current turn or one of its eight supplied prior turns.',
            );
        }
    }

    private function validateCardData(
        Validator $validator,
        string $cardType,
        array $data,
        string $prefix,
    ): void {
        $rules = $cardType === 'pain_point'
            ? [
                'text' => ['required', 'string', 'max:5000', 'regex:/\S/'],
                'category' => ['present', 'nullable', 'string', 'max:255', 'regex:/\S/'],
                'severity' => ['required', 'in:high,medium,low'],
            ]
            : [
                'name' => ['required', 'string', 'max:255', 'regex:/\S/'],
                'sentiment' => ['required', 'in:positive,negative,neutral,mixed'],
                'context' => ['required', 'string', 'max:5000', 'regex:/\S/'],
            ];

        $cardValidator = validator($data, $rules);

        foreach ($cardValidator->errors()->messages() as $attribute => $messages) {
            foreach ($messages as $message) {
                $validator->errors()->add($prefix.'data.'.$attribute, $message);
            }
        }

        $expectedKeys = array_keys($rules);
        $actualKeys = array_keys($data);
        sort($expectedKeys);
        sort($actualKeys);

        if ($actualKeys !== $expectedKeys) {
            $validator->errors()->add(
                $prefix.'data',
                'The evidence card data contains unexpected or missing fields.',
            );
        }
    }

    private function hasValidDeliveryTranscript(
        MeetingAnalysisDelivery $delivery,
        ConversationSession $session,
    ): bool {
        $transcript = $delivery->transcript;

        return $transcript !== null
            && $transcript->session_id === $session->id
            && $transcript->meeting_capture_session_id === $delivery->meeting_capture_session_id
            && $transcript->provider === MeetingProvider::Recall
            && $transcript->status === 'final'
            && filled($transcript->provider_item_id);
    }
}
