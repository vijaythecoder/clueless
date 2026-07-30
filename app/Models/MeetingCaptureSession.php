<?php

namespace App\Models;

use App\Enums\MeetingCaptureStatus;
use App\Enums\MeetingProvider;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingCaptureSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'conversation_session_id',
        'provider',
        'provider_bot_id',
        'platform',
        'status',
        'meeting_url_hash',
        'idempotency_key',
        'failure_code',
        'failure_message',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'provider' => MeetingProvider::class,
        'status' => MeetingCaptureStatus::class,
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class, 'conversation_session_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProviderEvent::class);
    }

    public function analysisDeliveries(): HasMany
    {
        return $this->hasMany(MeetingAnalysisDelivery::class);
    }
}
