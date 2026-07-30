<?php

namespace App\Data;

use App\Enums\MeetingCaptureStatus;
use Carbon\CarbonImmutable;

final readonly class NormalizedProviderEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventType,
        public ?string $providerEventId,
        public ?string $providerItemId,
        public ?string $utteranceKey,
        public ?string $providerParticipantId,
        public ?string $displayName,
        public ?string $email,
        public ?bool $isHost,
        public bool $isBot,
        public ?string $text,
        public ?int $startOffsetMs,
        public ?int $endOffsetMs,
        public ?CarbonImmutable $providerOccurredAt,
        public array $payload,
        public ?MeetingCaptureStatus $captureStatus,
        public ?string $failureCode,
        public ?string $failureMessage,
        public bool $isFinalTranscript,
        public bool $isPartialTranscript,
        public bool $isEmptyFinal,
        public ?string $providerBotId,
    ) {}
}
