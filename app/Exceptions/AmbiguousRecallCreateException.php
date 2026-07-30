<?php

namespace App\Exceptions;

final class AmbiguousRecallCreateException extends RecallApiException
{
    public function __construct(private readonly bool $recoverable = true)
    {
        parent::__construct(
            $recoverable
                ? 'Recall bot creation could not be confirmed.'
                : 'Recall bot recovery found multiple matches.',
            $recoverable ? 'recall_create_ambiguous' : 'recall_create_multiple_matches',
        );
    }

    public function isRecoverable(): bool
    {
        return $this->recoverable;
    }
}
