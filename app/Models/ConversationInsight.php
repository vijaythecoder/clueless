<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationInsight extends Model
{
    protected $fillable = [
        'session_id',
        'insight_type',
        'tool_call_id',
        'card_type',
        'approval_status',
        'data',
        'metadata',
        'captured_at',
        'semantic_key',
    ];

    protected $casts = [
        'data' => 'array',
        'metadata' => 'array',
        'captured_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class, 'session_id');
    }

    // Helper methods to access common data fields
    public function getName(): ?string
    {
        return $this->data['name'] ?? $this->data['text'] ?? null;
    }

    public function getType(): ?string
    {
        return $this->data['type'] ?? null;
    }

    public function getImportance(): ?string
    {
        return $this->data['importance'] ?? $this->data['priority'] ?? 'medium';
    }

    public function getSentiment(): ?string
    {
        return $this->data['sentiment'] ?? null;
    }
}
