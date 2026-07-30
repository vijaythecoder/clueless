<?php

namespace App\Enums;

enum SalesRole: string
{
    case Salesperson = 'salesperson';
    case Customer = 'customer';
    case Unknown = 'unknown';
    case Bot = 'bot';
}
