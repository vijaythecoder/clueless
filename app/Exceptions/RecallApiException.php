<?php

namespace App\Exceptions;

use RuntimeException;

class RecallApiException extends RuntimeException
{
    public function __construct(string $message = 'Recall capture request failed.', private readonly string $safeCode = 'recall_request_failed')
    {
        parent::__construct($message);
    }

    public function safeCode(): string
    {
        return $this->safeCode;
    }
}
