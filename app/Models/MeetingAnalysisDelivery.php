<?php

namespace App\Models;

use App\Enums\AnalysisDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingAnalysisDelivery extends Model
{
    protected $fillable = [
        'meeting_capture_session_id',
        'conversation_transcript_id',
        'status',
        'lease_token',
        'completed_lease_token_hash',
        'evidence_snapshot',
        'leased_at',
        'attempts',
        'last_error',
        'completed_at',
    ];

    protected $casts = [
        'status' => AnalysisDeliveryStatus::class,
        'evidence_snapshot' => 'array',
        'leased_at' => 'datetime',
        'attempts' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function capture(): BelongsTo
    {
        return $this->belongsTo(MeetingCaptureSession::class, 'meeting_capture_session_id');
    }

    public function transcript(): BelongsTo
    {
        return $this->belongsTo(ConversationTranscript::class, 'conversation_transcript_id');
    }
}
