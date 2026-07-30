<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConversationSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'customer_name',
        'customer_company',
        'started_at',
        'ended_at',
        'duration_seconds',
        'template_used',
        'openai_session_ids',
        'final_intent',
        'final_buying_stage',
        'final_engagement_level',
        'final_sentiment',
        'total_transcripts',
        'total_insights',
        'total_topics',
        'total_commitments',
        'total_action_items',
        'ai_summary',
        'user_notes',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'openai_session_ids' => 'array',
        'final_engagement_level' => 'integer',
        'total_transcripts' => 'integer',
        'total_insights' => 'integer',
        'total_topics' => 'integer',
        'total_commitments' => 'integer',
        'total_action_items' => 'integer',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transcripts(): HasMany
    {
        return $this->hasMany(ConversationTranscript::class, 'session_id')
            ->orderBy('order_index')
            ->orderBy('id');
    }

    public function captures(): HasMany
    {
        return $this->hasMany(MeetingCaptureSession::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(ConversationInsight::class, 'session_id')
            ->orderBy('captured_at')
            ->orderBy('id');
    }

    public function topics(): HasMany
    {
        return $this->insights()->where('insight_type', 'topic');
    }

    public function commitments(): HasMany
    {
        return $this->insights()->where('insight_type', 'commitment');
    }

    public function actionItems(): HasMany
    {
        return $this->insights()->where('insight_type', 'action_item');
    }

    public function keyInsights(): HasMany
    {
        return $this->insights()->where('insight_type', 'key_insight');
    }

    public function getDurationFormattedAttribute(): string
    {
        $minutes = floor($this->duration_seconds / 60);
        $seconds = $this->duration_seconds % 60;

        return sprintf('%02d:%02d', $minutes, $seconds);
    }
}
