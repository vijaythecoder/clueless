<?php

namespace App\Models;

use App\Enums\MeetingProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderEvent extends Model
{
    protected $fillable = [
        'meeting_capture_session_id',
        'provider',
        'provider_webhook_id',
        'provider_event_type',
        'provider_event_id',
        'provider_utterance_key',
        'meeting_participant_id',
        'payload',
        'provider_occurred_at',
        'received_at',
    ];

    protected $casts = [
        'provider' => MeetingProvider::class,
        'payload' => 'array',
        'provider_occurred_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function capture(): BelongsTo
    {
        return $this->belongsTo(MeetingCaptureSession::class, 'meeting_capture_session_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'meeting_participant_id');
    }
}
