<?php

namespace App\Http\Controllers;

use App\Exceptions\RecallApiException;
use App\Exceptions\RecallCapacityException;
use App\Http\Requests\StartMeetingCaptureRequest;
use App\Models\MeetingCaptureSession;
use App\Services\CaptureFailurePresenter;
use App\Services\MeetingCaptureService;
use App\Services\Recall\RecallCredentialService;
use Illuminate\Http\JsonResponse;

final class MeetingCaptureController extends Controller
{
    public function __construct(
        private readonly MeetingCaptureService $captureService,
        private readonly RecallCredentialService $credentials,
        private readonly CaptureFailurePresenter $failurePresenter,
    ) {}

    public function status(): JsonResponse
    {
        $status = $this->credentials->status();

        return response()->json([
            'configured' => $status['configured'],
            'region' => $status['region'],
            'webhook_url' => $status['webhook_url'],
            'webhook_ready' => $status['configured'] && $this->webhookIsReady($status['webhook_url']),
        ]);
    }

    public function store(StartMeetingCaptureRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            /** @var array{capture: MeetingCaptureSession, created: bool} $result */
            $result = $this->captureService->startWithConversation($validated);
        } catch (RecallCapacityException $exception) {
            return $this->recallError($exception);
        } catch (RecallApiException $exception) {
            return $this->recallError($exception);
        }

        return response()->json(
            $this->captureResponse($result['capture']),
            $result['created'] ? 201 : 200,
        );
    }

    public function stop(MeetingCaptureSession $capture): JsonResponse
    {
        try {
            $capture = $this->captureService->stop($capture);
        } catch (RecallCapacityException $exception) {
            return $this->recallError($exception);
        } catch (RecallApiException $exception) {
            return $this->recallError($exception);
        }

        return response()->json($this->captureResponse($capture));
    }

    /**
     * @return array<string, mixed>
     */
    private function captureResponse(MeetingCaptureSession $capture): array
    {
        $failure = $this->failurePresenter->forCapture($capture);

        return [
            'analysis_driver' => config('openai.recall_analysis.driver'),
            'capture' => [
                'id' => $capture->id,
                'conversation_id' => $capture->conversation_session_id,
                'provider' => $capture->provider->value,
                'status' => $capture->status->value,
                'failure_code' => $failure['code'],
                'failure_message' => $failure['message'],
            ],
            'links' => [
                'events' => route('meeting-captures.events', $capture, false),
                'stop' => route('meeting-captures.stop', $capture, false),
                'claim_analysis' => route('meeting-captures.analysis.claim', $capture, false),
                ...(config('openai.recall_analysis.driver') === 'responses'
                    ? ['insights' => route('meeting-captures.insights', $capture, false)]
                    : []),
            ],
        ];
    }

    private function webhookIsReady(?string $webhookUrl): bool
    {
        if (! is_string($webhookUrl) || filter_var($webhookUrl, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        if (strtolower((string) parse_url($webhookUrl, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }

        $webhookHost = strtolower((string) parse_url($webhookUrl, PHP_URL_HOST));
        $tunnelHost = $this->normalizedHost(config('services.recall.tunnel_host'));

        return $webhookHost !== '' && $webhookHost === $tunnelHost;
    }

    private function normalizedHost(mixed $host): string
    {
        if (! is_string($host) || trim($host) === '') {
            return '';
        }

        $candidate = str_contains($host, '://') ? $host : "https://{$host}";

        return strtolower((string) parse_url($candidate, PHP_URL_HOST));
    }

    private function recallError(RecallApiException $exception): JsonResponse
    {
        $failure = $this->failurePresenter->present(
            $this->rendererFailureCode($exception->safeCode()),
            true,
        );

        return response()->json([
            'error' => [
                'code' => $failure['code'],
                'message' => $failure['message'],
            ],
        ], $this->statusForRecallError($exception->safeCode()));
    }

    private function rendererFailureCode(string $internalCode): string
    {
        return match ($internalCode) {
            'recall_not_configured',
            'recall_invalid_meeting_url',
            'recall_rate_limited',
            'recall_capacity_unavailable',
            'recall_authentication_failed',
            'recall_webhook_timeout',
            'recall_bot_failed' => $internalCode,
            'recall_http_401', 'recall_http_403' => 'recall_authentication_failed',
            'recall_http_429' => 'recall_rate_limited',
            'recall_http_507' => 'recall_capacity_unavailable',
            default => 'recall_bot_failed',
        };
    }

    private function statusForRecallError(string $internalCode): int
    {
        return match ($internalCode) {
            'recall_not_configured' => 503,
            'recall_idempotency_conflict', 'meeting_capture_idempotency_conflict' => 409,
            'recall_rate_limited', 'recall_http_429' => 429,
            'recall_capacity_unavailable', 'recall_http_507' => 507,
            default => 502,
        };
    }
}
