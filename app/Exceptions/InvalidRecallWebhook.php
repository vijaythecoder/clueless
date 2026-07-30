<?php

namespace App\Exceptions;

use RuntimeException;

final class InvalidRecallWebhook extends RuntimeException
{
    public const REASON = 'invalid_recall_webhook';

    public function __construct()
    {
        parent::__construct(self::REASON);
    }
}
