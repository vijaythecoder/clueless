<?php

namespace App\Services\Recall;

use App\Contracts\MeetingCaptureProvider;
use App\Data\MeetingCaptureStartData;
use App\Enums\MeetingProvider;
use App\Exceptions\AmbiguousRecallCreateException;
use App\Exceptions\RecallApiException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class RecallCaptureProvider implements MeetingCaptureProvider
{
    public function __construct(private readonly RecallApiClient $client) {}

    public function provider(): MeetingProvider
    {
        return MeetingProvider::Recall;
    }

    /** @return array<string, mixed> */
    public function start(MeetingCaptureStartData $data): array
    {
        try {
            return Cache::lock("recall-bot-create:{$data->idempotencyKey}", 30)
                ->block(5, fn (): array => $this->startLocked($data));
        } catch (LockTimeoutException) {
            throw new RecallApiException(
                'Recall bot creation is busy. Please retry.',
                'recall_create_busy',
            );
        }
    }

    /** @return array<string, mixed> */
    private function startLocked(MeetingCaptureStartData $data): array
    {
        $existingBots = $this->client->findByIdempotencyKey($data->idempotencyKey);

        if (count($existingBots) === 1) {
            return $existingBots[0];
        }

        if (count($existingBots) > 1) {
            throw new AmbiguousRecallCreateException(recoverable: false);
        }

        return $this->client->create($data);
    }

    public function stop(string $providerCaptureId): void
    {
        $this->client->leave($providerCaptureId);
    }

    /** @return array<string, mixed> */
    public function retrieve(string $providerCaptureId): array
    {
        return $this->client->retrieve($providerCaptureId);
    }
}
