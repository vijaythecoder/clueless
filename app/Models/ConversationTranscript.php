<?php

namespace App\Models;

use App\Enums\MeetingProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ConversationTranscript extends Model
{
    protected $fillable = [
        'session_id',
        'speaker',
        'source_stream',
        'text',
        'spoken_at',
        'openai_item_id',
        'status',
        'group_id',
        'system_category',
        'metadata',
        'order_index',
        'meeting_capture_session_id',
        'meeting_participant_id',
        'provider',
        'provider_item_id',
        'started_offset_ms',
        'ended_offset_ms',
    ];

    protected $casts = [
        'spoken_at' => 'datetime',
        'order_index' => 'integer',
        'metadata' => 'array',
        'provider' => MeetingProvider::class,
        'started_offset_ms' => 'integer',
        'ended_offset_ms' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class, 'session_id');
    }

    public function capture(): BelongsTo
    {
        return $this->belongsTo(MeetingCaptureSession::class, 'meeting_capture_session_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'meeting_participant_id');
    }

    public function analysisDelivery(): HasOne
    {
        return $this->hasOne(MeetingAnalysisDelivery::class, 'conversation_transcript_id');
    }

    public function getSpeakerLabelAttribute(): string
    {
        return match ($this->speaker) {
            'salesperson' => 'You',
            'customer' => 'Customer',
            'system' => 'System',
            default => ucfirst($this->speaker),
        };
    }

    public function getSpeakerColorAttribute(): string
    {
        return match ($this->speaker) {
            'salesperson' => 'text-blue-600',
            'customer' => 'text-green-600',
            'system' => 'text-gray-500',
            default => 'text-gray-700',
        };
    }
}
