<?php

namespace App\Exceptions;

use RuntimeException;

final class OpenAIResponsesException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 502,
        private readonly array $safePayload = ['error' => ['type' => 'upstream_error']],
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function safePayload(): array
    {
        return $this->safePayload;
    }
}
