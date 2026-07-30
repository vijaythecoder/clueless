<?php

namespace App\Contracts;

use App\Data\MeetingCaptureStartData;
use App\Enums\MeetingProvider;

interface MeetingCaptureProvider
{
    public function provider(): MeetingProvider;

    /** @return array<string, mixed> */
    public function start(MeetingCaptureStartData $data): array;

    public function stop(string $providerCaptureId): void;

    /** @return array<string, mixed> */
    public function retrieve(string $providerCaptureId): array;
}
