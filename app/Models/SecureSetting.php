<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecureSetting extends Model
{
    public const OPENAI_API_KEY = 'openai_api_key';

    public const RECALL_API_KEY = 'recall_api_key';

    public const RECALL_WEBHOOK_SECRET = 'recall_webhook_secret';

    protected $fillable = [
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'encrypted',
    ];
}
