<?php

namespace App\Services\Recall;

use App\Exceptions\InvalidRecallWebhook;
use DomainException;

final class RecallWebhookVerifier
{
    public function __construct(private readonly RecallCredentialService $credentials) {}

    public function verify(
        string $rawBody,
        string $webhookId,
        string $webhookTimestamp,
        string $webhookSignature,
    ): void {
        $signingKey = $this->signingKey();

        if ($webhookId === '' || ! ctype_digit($webhookTimestamp)) {
            throw new InvalidRecallWebhook;
        }

        $timestamp = filter_var($webhookTimestamp, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $tolerance = max(0, (int) config('services.recall.webhook_tolerance_seconds', 300));

        if ($timestamp === false || abs(time() - $timestamp) > $tolerance) {
            throw new InvalidRecallWebhook;
        }

        $expected = hash_hmac(
            'sha256',
            implode('.', [$webhookId, $webhookTimestamp, $rawBody]),
            $signingKey,
            true,
        );

        foreach (explode(' ', $webhookSignature) as $token) {
            [$version, $signature] = array_pad(explode(',', $token, 2), 2, null);

            if ($version !== 'v1' || ! is_string($signature)) {
                continue;
            }

            $candidate = base64_decode($signature, true);

            if ($candidate !== false && strlen($candidate) === strlen($expected) && hash_equals($expected, $candidate)) {
                return;
            }
        }

        throw new InvalidRecallWebhook;
    }

    private function signingKey(): string
    {
        try {
            $secret = $this->credentials->webhookSecret();
        } catch (DomainException) {
            throw new InvalidRecallWebhook;
        }

        if (! str_starts_with($secret, 'whsec_')) {
            throw new InvalidRecallWebhook;
        }

        $signingKey = base64_decode(substr($secret, 6), true);

        if ($signingKey === false || $signingKey === '') {
            throw new InvalidRecallWebhook;
        }

        return $signingKey;
    }
}
