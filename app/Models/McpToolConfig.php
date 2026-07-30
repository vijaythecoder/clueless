<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class McpToolConfig extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'server_label',
        'server_url',
        'connector_id',
        'authorization',
        'headers',
        'allowed_tools',
        'require_approval',
        'is_enabled',
        'is_read_only',
        'metadata',
    ];

    protected $casts = [
        'authorization' => 'encrypted',
        'headers' => 'encrypted:array',
        'allowed_tools' => 'array',
        'is_enabled' => 'boolean',
        'is_read_only' => 'boolean',
        'metadata' => 'array',
    ];
}
