<?php

namespace App\Services;

use App\Contracts\MeetingCaptureProvider;
use App\Data\MeetingCaptureStartData;
use App\Enums\MeetingProvider;

final class LocalCaptureProvider implements MeetingCaptureProvider
{
    public function provider(): MeetingProvider
    {
        return MeetingProvider::Local;
    }

    /** @return array<string, mixed> */
    public function start(MeetingCaptureStartData $data): array
    {
        return [
            'id' => $data->captureId,
            'status' => 'in_call_recording',
            'metadata' => ['capture_mode' => 'local'],
        ];
    }

    public function stop(string $providerCaptureId): void {}

    /** @return array<string, mixed> */
    public function retrieve(string $providerCaptureId): array
    {
        return [
            'id' => $providerCaptureId,
            'status' => 'in_call_recording',
            'metadata' => ['capture_mode' => 'local'],
        ];
    }
}
