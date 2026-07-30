<?php

namespace App\Enums;

enum AnalysisDeliveryStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
