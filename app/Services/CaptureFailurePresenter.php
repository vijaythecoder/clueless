<?php

namespace App\Services;

use App\Enums\MeetingCaptureStatus;
use App\Models\MeetingCaptureSession;

final class CaptureFailurePresenter
{
    /**
     * @return array{code: ?string, message: ?string}
     */
    public function forCapture(MeetingCaptureSession $capture): array
    {
        return $this->present(
            $capture->failure_code,
            $capture->status === MeetingCaptureStatus::Failed,
        );
    }

    /**
     * @return array{code: ?string, message: ?string}
     */
    public function present(mixed $failureCode, bool $isFailed): array
    {
        if (! filled($failureCode) && ! $isFailed) {
            return ['code' => null, 'message' => null];
        }

        $code = is_string($failureCode) && array_key_exists($failureCode, $this->messages())
            ? $failureCode
            : 'recall_bot_failed';

        return [
            'code' => $code,
            'message' => $this->messages()[$code],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'recall_not_configured' => 'Recall capture is not configured.',
            'recall_invalid_meeting_url' => 'The Teams meeting URL is invalid.',
            'recall_rate_limited' => 'Recall is temporarily rate limited.',
            'recall_capacity_unavailable' => 'Recall capture capacity is unavailable.',
            'recall_authentication_failed' => 'Recall authentication failed.',
            'recall_webhook_timeout' => 'Realtime meeting updates were interrupted.',
            'recall_bot_failed' => 'Meeting capture failed.',
        ];
    }
}
