<?php

namespace App\Data;

final readonly class MeetingCaptureStartData
{
    public function __construct(
        public string $captureId,
        public string $meetingUrl,
        public string $idempotencyKey,
    ) {}
}
