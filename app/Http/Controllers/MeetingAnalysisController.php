<?php

namespace App\Http\Controllers;

use App\Enums\AnalysisDeliveryStatus;
use App\Exceptions\MissingOpenAIKeyException;
use App\Exceptions\OpenAIResponsesException;
use App\Http\Requests\AcknowledgeMeetingAnalysisRequest;
use App\Http\Requests\AnalyzeMeetingAnalysisRequest;
use App\Http\Requests\ClaimMeetingAnalysisRequest;
use App\Models\MeetingAnalysisDelivery;
use App\Models\MeetingCaptureSession;
use App\Services\MeetingAnalysisDeliveryService;
use App\Services\RecallCopilotAnalysisService;
use Illuminate\Http\JsonResponse;

final class MeetingAnalysisController extends Controller
{
    public function __construct(
        private readonly MeetingAnalysisDeliveryService $deliveries,
        private readonly RecallCopilotAnalysisService $copilotAnalysis,
    ) {}

    public function claim(
        ClaimMeetingAnalysisRequest $request,
        MeetingCaptureSession $capture,
    ): JsonResponse {
        return response()->json($this->deliveries->claim(
            $capture,
            (int) $request->validated('through_cursor'),
        ));
    }

    public function acknowledge(
        AcknowledgeMeetingAnalysisRequest $request,
        MeetingCaptureSession $capture,
        MeetingAnalysisDelivery $delivery,
    ): JsonResponse {
        $validated = $request->validated();
        $delivery = $this->deliveries->acknowledge(
            $capture,
            $delivery,
            $validated['lease_token'],
            AnalysisDeliveryStatus::from($validated['status']),
            $validated['error_code'] ?? null,
        );

        return response()->json([
            'id' => $delivery->id,
            'status' => $delivery->status->value,
        ]);
    }

    public function analyze(
        AnalyzeMeetingAnalysisRequest $request,
        MeetingCaptureSession $capture,
        MeetingAnalysisDelivery $delivery,
    ): JsonResponse {
        abort_unless(config('openai.recall_analysis.driver') === 'responses', 404);

        try {
            return response()->json($this->copilotAnalysis->analyze(
                $capture,
                $delivery,
                $request->validated('lease_token'),
            ));
        } catch (MissingOpenAIKeyException) {
            return response()->json([
                'error' => ['type' => 'openai_not_configured'],
            ], 503);
        } catch (OpenAIResponsesException $exception) {
            $status = $exception->statusCode() === 429 ? 429 : 502;

            return response()->json($exception->safePayload(), $status);
        }
    }
}
