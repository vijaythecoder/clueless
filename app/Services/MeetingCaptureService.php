<?php

namespace App\Services;

use App\Data\MeetingCaptureStartData;
use App\Enums\MeetingCaptureStatus;
use App\Enums\MeetingProvider;
use App\Exceptions\AmbiguousRecallCreateException;
use App\Exceptions\RecallApiException;
use App\Models\ConversationSession;
use App\Models\MeetingCaptureSession;
use App\Rules\TeamsMeetingUrl;
use App\Services\Recall\RecallCaptureProvider;
use App\Services\Recall\RecallCredentialService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class MeetingCaptureService
{
    public function __construct(
        private readonly RecallCredentialService $credentials,
        private readonly RecallCaptureProvider $recallProvider,
    ) {}

    /**
     * @param  array{
     *     provider: string,
     *     meeting_url?: string|null,
     *     idempotency_key: string,
     *     template_used?: string|null,
     *     customer_name?: string|null,
     *     customer_company?: string|null
     * }  $attributes
     * @return array{capture: MeetingCaptureSession, created: bool}
     */
    public function startWithConversation(array $attributes): array
    {
        $provider = MeetingProvider::from($attributes['provider']);
        $idempotencyKey = $attributes['idempotency_key'];
        $meetingUrl = null;

        if ($provider === MeetingProvider::Recall) {
            $meetingUrl = $this->canonicalMeetingUrl($attributes['meeting_url'] ?? null);
        }

        $existing = MeetingCaptureSession::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return [
                'capture' => $this->resumeExistingCapture($existing, $provider, $meetingUrl),
                'created' => false,
            ];
        }

        if ($provider === MeetingProvider::Recall) {
            $this->ensureRecallCaptureIsReady();
        }

        try {
            [$conversation, $capture] = DB::transaction(function () use (
                $attributes,
                $provider,
                $idempotencyKey,
                $meetingUrl,
            ): array {
                $conversation = ConversationSession::query()->create([
                    'user_id' => null,
                    'started_at' => now(),
                    'template_used' => $attributes['template_used'] ?? null,
                    'customer_name' => $attributes['customer_name'] ?? null,
                    'customer_company' => $attributes['customer_company'] ?? null,
                ]);
                $capture = MeetingCaptureSession::query()->create([
                    'conversation_session_id' => $conversation->id,
                    'provider' => $provider,
                    'platform' => $provider === MeetingProvider::Recall ? 'teams' : 'local',
                    'status' => $provider === MeetingProvider::Recall
                        ? MeetingCaptureStatus::Creating
                        : MeetingCaptureStatus::Active,
                    'meeting_url_hash' => $meetingUrl === null ? null : hash('sha256', $meetingUrl),
                    'idempotency_key' => $idempotencyKey,
                    'started_at' => $provider === MeetingProvider::Local ? now() : null,
                ]);

                return [$conversation, $capture];
            });
        } catch (QueryException $exception) {
            $existing = MeetingCaptureSession::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            return [
                'capture' => $this->resumeExistingCapture($existing, $provider, $meetingUrl),
                'created' => false,
            ];
        }

        if ($provider === MeetingProvider::Recall) {
            $capture = $this->startRecall($conversation, $meetingUrl, $idempotencyKey);
        }

        return ['capture' => $capture, 'created' => true];
    }

    public function startRecall(
        ConversationSession $conversation,
        string $meetingUrl,
        string $idempotencyKey,
    ): MeetingCaptureSession {
        $meetingUrlRule = new TeamsMeetingUrl;

        Validator::make(
            ['meeting_url' => $meetingUrl],
            ['meeting_url' => ['required', 'url', 'max:2048', $meetingUrlRule]],
        )->validate();

        $meetingUrl = $meetingUrlRule->canonicalize($meetingUrl);
        $meetingUrlHash = hash('sha256', $meetingUrl);
        $this->ensureRecallIsConfigured();

        $capture = DB::transaction(function () use ($conversation, $meetingUrlHash, $idempotencyKey): MeetingCaptureSession {
            return MeetingCaptureSession::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'conversation_session_id' => $conversation->id,
                    'provider' => MeetingProvider::Recall,
                    'platform' => 'teams',
                    'status' => MeetingCaptureStatus::Creating,
                    'meeting_url_hash' => $meetingUrlHash,
                ],
            );
        });

        if (! is_string($capture->meeting_url_hash)
            || ! hash_equals($capture->meeting_url_hash, $meetingUrlHash)) {
            throw new RecallApiException(
                'The idempotency key is already bound to another meeting.',
                'recall_idempotency_conflict',
            );
        }

        if ($capture->provider_bot_id !== null || $this->isTerminal($capture->status)) {
            return $capture;
        }

        try {
            $bot = $this->recallProvider->start(new MeetingCaptureStartData(
                $capture->id,
                $meetingUrl,
                $idempotencyKey,
            ));
            $botId = $bot['id'] ?? null;

            if (! is_string($botId) || $botId === '') {
                throw new RecallApiException('Recall capture returned an invalid response.', 'recall_invalid_response');
            }

            $providerStatus = $bot['status'] ?? null;
            $failure = $this->terminalCreateFailure($providerStatus);

            $capture->forceFill([
                'provider_bot_id' => $botId,
                'status' => $this->mapStatus($providerStatus, $capture->status),
                'failure_code' => $failure['code'] ?? null,
                'failure_message' => $failure['message'] ?? null,
            ])->save();
        } catch (AmbiguousRecallCreateException $exception) {
            if ($exception->isRecoverable()) {
                $this->recordFailure($capture, $exception->safeCode(), $exception->getMessage());
            } else {
                $this->markFailed($capture, $exception->safeCode(), $exception->getMessage());
            }

            throw $exception;
        } catch (RecallApiException $exception) {
            if ($exception->safeCode() === 'recall_create_busy') {
                $this->recordFailure($capture, $exception->safeCode(), $exception->getMessage());
            } else {
                $this->markFailed($capture, $exception->safeCode(), $exception->getMessage());
            }

            throw $exception;
        } catch (Throwable) {
            $exception = new RecallApiException('Unable to start Recall capture.', 'recall_capture_start_failed');
            $this->markFailed($capture, $exception->safeCode(), $exception->getMessage());

            throw $exception;
        }

        return $capture->fresh();
    }

    public function stop(MeetingCaptureSession $capture): MeetingCaptureSession
    {
        if ($this->isTerminal($capture->status)
            || ($capture->status === MeetingCaptureStatus::Stopping && $capture->failure_code === null)) {
            return $capture;
        }

        if ($capture->status !== MeetingCaptureStatus::Stopping) {
            $capture->forceFill(['status' => MeetingCaptureStatus::Stopping])->save();
        }

        try {
            if ($capture->provider === MeetingProvider::Recall && $capture->provider_bot_id !== null) {
                $this->recallProvider->stop($capture->provider_bot_id);
            }
        } catch (RecallApiException $exception) {
            $this->recordFailure($capture, $exception->safeCode(), $exception->getMessage());

            throw $exception;
        } catch (Throwable) {
            $exception = new RecallApiException('Unable to stop Recall capture.', 'recall_stop_failed');
            $this->recordFailure($capture, $exception->safeCode(), $exception->getMessage());

            throw $exception;
        }

        $capture->forceFill([
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        return $capture->fresh();
    }

    private function ensureRecallIsConfigured(): void
    {
        if (! $this->credentials->isConfigured() || ! filled(config('services.recall.webhook_url'))) {
            throw new RecallApiException('Recall capture is not configured.', 'recall_not_configured');
        }
    }

    private function ensureRecallCaptureIsReady(): void
    {
        $this->ensureRecallIsConfigured();

        $webhookUrl = config('services.recall.webhook_url');
        $tunnelHost = $this->normalizedHost(config('services.recall.tunnel_host'));

        if (
            ! is_string($webhookUrl)
            || filter_var($webhookUrl, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($webhookUrl, PHP_URL_SCHEME)) !== 'https'
            || strtolower((string) parse_url($webhookUrl, PHP_URL_HOST)) !== $tunnelHost
        ) {
            throw new RecallApiException('Recall capture is not configured.', 'recall_not_configured');
        }
    }

    private function canonicalMeetingUrl(mixed $meetingUrl): string
    {
        $rule = new TeamsMeetingUrl;
        $validated = Validator::make(
            ['meeting_url' => $meetingUrl],
            ['meeting_url' => ['required', 'string', 'max:2048', $rule]],
        )->validate();

        return $rule->canonicalize($validated['meeting_url']);
    }

    private function resumeExistingCapture(
        MeetingCaptureSession $capture,
        MeetingProvider $provider,
        ?string $meetingUrl,
    ): MeetingCaptureSession {
        if ($capture->provider !== $provider) {
            throw new RecallApiException(
                'The idempotency key is already bound to another capture.',
                'meeting_capture_idempotency_conflict',
            );
        }

        if ($provider === MeetingProvider::Local) {
            return $capture;
        }

        if ($meetingUrl === null) {
            throw new RecallApiException('A Teams meeting URL is required.', 'recall_invalid_meeting_url');
        }

        $meetingUrlHash = hash('sha256', $meetingUrl);

        if (
            ! is_string($capture->meeting_url_hash)
            || ! hash_equals($capture->meeting_url_hash, $meetingUrlHash)
        ) {
            throw new RecallApiException(
                'The idempotency key is already bound to another meeting.',
                'recall_idempotency_conflict',
            );
        }

        if (
            $capture->provider_bot_id !== null
            || $this->isTerminal($capture->status)
            || $capture->status === MeetingCaptureStatus::Stopping
        ) {
            return $capture;
        }

        $this->ensureRecallCaptureIsReady();

        return $this->startRecall(
            $capture->conversation,
            $meetingUrl,
            $capture->idempotency_key,
        );
    }

    private function normalizedHost(mixed $host): string
    {
        if (! is_string($host) || trim($host) === '') {
            return '';
        }

        $candidate = str_contains($host, '://') ? $host : "https://{$host}";

        return strtolower((string) parse_url($candidate, PHP_URL_HOST));
    }

    private function markFailed(MeetingCaptureSession $capture, string $code, string $message): void
    {
        $capture->forceFill([
            'status' => MeetingCaptureStatus::Failed,
            'failure_code' => $code,
            'failure_message' => $message,
        ])->save();
    }

    private function recordFailure(MeetingCaptureSession $capture, string $code, string $message): void
    {
        $capture->forceFill([
            'failure_code' => $code,
            'failure_message' => $message,
        ])->save();
    }

    private function mapStatus(mixed $providerStatus, MeetingCaptureStatus $current): MeetingCaptureStatus
    {
        return match ($providerStatus) {
            'ready' => MeetingCaptureStatus::Creating,
            'joining_call', 'in_call_not_recording', 'recording_permission_allowed' => MeetingCaptureStatus::Joining,
            'in_waiting_room' => MeetingCaptureStatus::WaitingRoom,
            'in_call_recording' => MeetingCaptureStatus::Active,
            'call_ended', 'done' => MeetingCaptureStatus::Ended,
            'recording_permission_denied', 'fatal' => MeetingCaptureStatus::Failed,
            default => $current,
        };
    }

    /** @return array{code: string, message: string}|null */
    private function terminalCreateFailure(mixed $providerStatus): ?array
    {
        return match ($providerStatus) {
            'recording_permission_denied' => [
                'code' => 'recall_recording_permission_denied',
                'message' => 'Recall recording permission was denied.',
            ],
            'fatal' => [
                'code' => 'recall_fatal',
                'message' => 'Recall reported a fatal bot failure.',
            ],
            default => null,
        };
    }

    private function isTerminal(MeetingCaptureStatus $status): bool
    {
        return in_array($status, [MeetingCaptureStatus::Ended, MeetingCaptureStatus::Failed], true);
    }
}
