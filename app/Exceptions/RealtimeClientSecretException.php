<?php

namespace App\Exceptions;

use RuntimeException;

class RealtimeClientSecretException extends RuntimeException
{
    public function __construct(string $message, private int $statusCode = 500, private ?array $payload = null)
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function payload(): ?array
    {
        return $this->payload;
    }
}
