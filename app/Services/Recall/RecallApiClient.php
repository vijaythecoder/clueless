<?php

namespace App\Services\Recall;

use App\Data\MeetingCaptureStartData;
use App\Exceptions\AmbiguousRecallCreateException;
use App\Exceptions\RecallApiException;
use App\Exceptions\RecallCapacityException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class RecallApiClient
{
    private readonly Closure $sleep;

    public function __construct(
        private readonly RecallCredentialService $credentials,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /** @return array<string, mixed> */
    public function create(MeetingCaptureStartData $data): array
    {
        try {
            $response = $this->withRateLimitRetries(
                fn (): Response => $this->request()->post('/api/v1/bot/', $this->createPayload($data)),
            );
        } catch (ConnectionException) {
            return $this->recoverCreate($data->idempotencyKey);
        }

        return $this->responseData($response);
    }

    /** @return array<string, mixed> */
    public function retrieve(string $providerCaptureId): array
    {
        return $this->responseData($this->withRateLimitRetries(
            fn (): Response => $this->request()->get("/api/v1/bot/{$providerCaptureId}/"),
        ));
    }

    public function leave(string $providerCaptureId): void
    {
        $response = $this->withRateLimitRetries(
            fn (): Response => $this->request()->post("/api/v1/bot/{$providerCaptureId}/leave_call/"),
        );

        if (in_array($response->status(), [400, 404, 405], true)) {
            return;
        }

        if ($response->failed()) {
            $this->responseData($response);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function findByIdempotencyKey(string $idempotencyKey): array
    {
        $response = $this->withRateLimitRetries(
            fn (): Response => $this->request()->get('/api/v1/bot/', [
                'metadata__clueless_idempotency_key' => $idempotencyKey,
            ]),
        );
        $data = $this->responseData($response);
        $bots = $data['results'] ?? $data['data'] ?? $data;

        if (! is_array($bots)) {
            return [];
        }

        return array_values(array_filter($bots, fn (mixed $bot): bool => is_array($bot)
            && ($bot['metadata']['clueless_idempotency_key'] ?? null) === $idempotencyKey));
    }

    /** @return array<string, mixed> */
    private function recoverCreate(string $idempotencyKey): array
    {
        $bots = $this->findByIdempotencyKey($idempotencyKey);

        if (count($bots) === 1) {
            return $bots[0];
        }

        throw new AmbiguousRecallCreateException(recoverable: count($bots) === 0);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.recall.base_url'), '/'))
            ->withHeaders(['Authorization' => $this->credentials->apiKey()])
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('services.recall.connect_timeout', 5))
            ->timeout((int) config('services.recall.timeout', 15));
    }

    /** @return array<string, mixed> */
    private function createPayload(MeetingCaptureStartData $data): array
    {
        $events = [
            'participant_events.join',
            'participant_events.update',
            'participant_events.leave',
            'transcript.data',
        ];

        if (config('services.recall.partial_transcripts', false)) {
            $events[] = 'transcript.partial_data';
        }

        return [
            'meeting_url' => $data->meetingUrl,
            'bot_name' => 'Clueless Copilot',
            'metadata' => [
                'clueless_capture_id' => $data->captureId,
                'clueless_idempotency_key' => $data->idempotencyKey,
            ],
            'recording_config' => [
                'transcript' => [
                    'provider' => [
                        'recallai_streaming' => [
                            'mode' => 'prioritize_low_latency',
                            'language_code' => 'en',
                        ],
                    ],
                    'diarization' => [
                        'use_separate_streams_when_available' => true,
                    ],
                ],
                'realtime_endpoints' => [[
                    'type' => 'webhook',
                    'url' => config('services.recall.webhook_url'),
                    'events' => $events,
                ]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function responseData(Response $response): array
    {
        if ($response->status() === 507) {
            throw new RecallCapacityException;
        }

        if ($response->failed()) {
            throw new RecallApiException('Recall capture request failed.', 'recall_http_'.$response->status());
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RecallApiException('Recall capture returned an invalid response.', 'recall_invalid_response');
        }

        return $data;
    }

    private function retryAfterSeconds(Response $response): int
    {
        $retryAfter = $response->header('Retry-After');

        return min(5, max(0, filter_var($retryAfter, FILTER_VALIDATE_INT) === false ? 0 : (int) $retryAfter));
    }

    private function withRateLimitRetries(Closure $request): Response
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = $request();

            if ($response->status() !== 429 || $attempt === 3) {
                return $response;
            }

            ($this->sleep)($this->retryAfterSeconds($response));
        }

        throw new RecallApiException;
    }
}
