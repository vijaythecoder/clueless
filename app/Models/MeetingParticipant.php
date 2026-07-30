<?php

namespace App\Models;

use App\Enums\SalesRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingParticipant extends Model
{
    protected $fillable = [
        'meeting_capture_session_id',
        'provider_participant_id',
        'display_name',
        'email',
        'email_hash',
        'is_host',
        'is_bot',
        'sales_role',
    ];

    protected $casts = [
        'email' => 'encrypted',
        'is_host' => 'boolean',
        'is_bot' => 'boolean',
        'sales_role' => SalesRole::class,
    ];

    public function capture(): BelongsTo
    {
        return $this->belongsTo(MeetingCaptureSession::class, 'meeting_capture_session_id');
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class);
    }

    public function transcripts(): HasMany
    {
        return $this->hasMany(ConversationTranscript::class);
    }
}
