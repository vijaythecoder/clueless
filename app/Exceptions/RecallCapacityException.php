<?php

namespace App\Exceptions;

final class RecallCapacityException extends RecallApiException
{
    public function __construct()
    {
        parent::__construct('Recall capture capacity is unavailable.', 'recall_capacity_unavailable');
    }
}
