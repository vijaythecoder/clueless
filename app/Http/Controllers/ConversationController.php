<?php

namespace App\Http\Controllers;

use App\Http\Requests\EndConversationRequest;
use App\Http\Requests\PersistInsightsRequest;
use App\Http\Requests\PersistTranscriptsRequest;
use App\Models\ConversationInsight;
use App\Models\ConversationSession;
use App\Models\ConversationTranscript;
use App\Models\MeetingCaptureSession;
use App\Services\CaptureFailurePresenter;
use App\Services\ConversationPersistenceService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ConversationController extends Controller
{
    private const TRANSCRIPTS_PER_PAGE = 200;

    private const INSIGHTS_PER_PAGE = 100;

    public function __construct(
        private ConversationPersistenceService $conversationPersistenceService,
        private CaptureFailurePresenter $failurePresenter,
    ) {}

    /**
     * Display a listing of conversation sessions.
     */
    public function index()
    {
        $sessions = ConversationSession::query()
            ->with(['user']) // Eager load user relationship
            ->orderBy('started_at', 'desc')
            ->paginate(20);

        return Inertia::render('Conversations/Index', [
            'sessions' => $sessions,
        ]);
    }

    /**
     * Display a specific conversation session.
     */
    public function show(ConversationSession $session)
    {
        $capture = $session->captures()
            ->latest('created_at')
            ->latest('id')
            ->first();

        return Inertia::render('Conversations/Show', [
            'session' => $this->serializeSession($session),
            'capture' => $capture === null ? null : $this->serializeCapture($capture),
            'transcripts' => $session->transcripts()
                ->with('participant')
                ->paginate(self::TRANSCRIPTS_PER_PAGE, ['*'], 'transcripts_page')
                ->withQueryString()
                ->through(fn (ConversationTranscript $transcript): array => $this->serializeTranscript($transcript)),
            'insights' => $session->insights()
                ->reorder('captured_at', 'desc')
                ->orderByDesc('id')
                ->paginate(self::INSIGHTS_PER_PAGE, ['*'], 'insights_page')
                ->withQueryString()
                ->through(fn (ConversationInsight $insight): array => $this->serializeInsight($insight)),
        ]);
    }

    /**
     * Start a new conversation session.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'template_used' => 'nullable|string',
            'customer_name' => 'nullable|string',
            'customer_company' => 'nullable|string',
            'openai_session_ids' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        // No user ID needed for single-user desktop app
        $session = ConversationSession::create([
            'user_id' => null,
            'started_at' => now(),
            'template_used' => $validated['template_used'] ?? null,
            'openai_session_ids' => $validated['openai_session_ids'] ?? null,
            'customer_name' => $validated['customer_name'] ?? null,
            'customer_company' => $validated['customer_company'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return response()->json([
            'session_id' => $session->id,
            'message' => 'Session started successfully',
        ]);
    }

    /**
     * End a conversation session.
     */
    public function end(ConversationSession $session, EndConversationRequest $request)
    {
        $this->conversationPersistenceService->finalize($session, $request->validated());

        return response()->json([
            'message' => 'Session ended successfully',
        ]);
    }

    /**
     * Save a transcript to the session.
     */
    public function saveTranscript(ConversationSession $session, PersistTranscriptsRequest $request)
    {
        $transcript = $this->conversationPersistenceService->persistTranscript(
            $session,
            $request->transcripts()[0],
        );

        return response()->json([
            'transcript_id' => $transcript->id,
            'message' => 'Transcript saved successfully',
        ]);
    }

    /**
     * Save batch transcripts.
     */
    public function saveBatchTranscripts(ConversationSession $session, PersistTranscriptsRequest $request)
    {
        $this->conversationPersistenceService->persistTranscripts($session, $request->transcripts());

        return response()->json([
            'message' => 'Transcripts saved successfully',
        ]);
    }

    /**
     * Save an insight to the session.
     */
    public function saveInsight(ConversationSession $session, PersistInsightsRequest $request)
    {
        $insight = $this->conversationPersistenceService->persistInsight(
            $session,
            $request->insights()[0],
        );

        return response()->json([
            'insight_id' => $insight->id,
            'message' => 'Insight saved successfully',
        ]);
    }

    /**
     * Save batch insights.
     */
    public function saveBatchInsights(ConversationSession $session, PersistInsightsRequest $request)
    {
        $this->conversationPersistenceService->persistInsights($session, $request->insights());

        return response()->json([
            'message' => 'Insights saved successfully',
        ]);
    }

    /**
     * Update session notes.
     */
    public function updateNotes(ConversationSession $session, Request $request)
    {
        // No auth check needed for single-user desktop app

        $validated = $request->validate([
            'user_notes' => 'nullable|string',
        ]);

        $session->update([
            'user_notes' => $validated['user_notes'],
        ]);

        return response()->json([
            'message' => 'Notes updated successfully',
        ]);
    }

    /**
     * Update session title.
     */
    public function updateTitle(ConversationSession $session, Request $request)
    {
        // No auth check needed for single-user desktop app

        $validated = $request->validate([
            'title' => 'required|string|max:255',
        ]);

        $session->update([
            'title' => $validated['title'],
        ]);

        return response()->json([
            'message' => 'Title updated successfully',
        ]);
    }

    /**
     * Delete a conversation session.
     */
    public function destroy(ConversationSession $session)
    {
        // No auth check needed for single-user desktop app

        $session->delete();

        return redirect()->route('conversations.index')
            ->with('message', 'Conversation deleted successfully');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSession(ConversationSession $session): array
    {
        return $session->only([
            'id',
            'title',
            'customer_name',
            'customer_company',
            'started_at',
            'ended_at',
            'duration_seconds',
            'template_used',
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
        ]);
    }

    /**
     * @return array{provider: string, status: string, failure_code: ?string, failure_message: ?string}
     */
    private function serializeCapture(MeetingCaptureSession $capture): array
    {
        $failure = $this->failurePresenter->forCapture($capture);

        return [
            'provider' => $capture->provider->value,
            'status' => $capture->status->value,
            'failure_code' => $failure['code'],
            'failure_message' => $failure['message'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTranscript(ConversationTranscript $transcript): array
    {
        $provider = $transcript->provider?->value;
        $salesRole = $transcript->participant?->sales_role?->value ?? $transcript->speaker;
        $participantDisplayName = $provider === 'recall'
            ? ($transcript->participant?->display_name ?: match ($salesRole) {
                'bot' => 'Meeting bot',
                default => 'Unknown participant',
            })
            : null;

        return [
            'id' => $transcript->id,
            'speaker' => $transcript->speaker,
            'speaker_label' => $participantDisplayName ?? $transcript->speaker_label,
            'participant_display_name' => $participantDisplayName,
            'sales_role' => $salesRole,
            'is_you' => $provider === 'recall' && $salesRole === 'salesperson',
            'text' => $transcript->text,
            'spoken_at' => $transcript->spoken_at,
            'order_index' => $transcript->order_index,
            'group_id' => $transcript->group_id,
            'system_category' => $transcript->system_category,
            'source_stream' => $transcript->source_stream,
            'status' => $transcript->status,
            'provider' => $provider,
            'provider_item_id' => $transcript->provider_item_id,
            'started_offset_ms' => $transcript->started_offset_ms,
            'ended_offset_ms' => $transcript->ended_offset_ms,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeInsight(ConversationInsight $insight): array
    {
        $metadata = is_array($insight->metadata) ? $insight->metadata : [];
        $evidenceItemIds = collect($metadata['evidence_item_ids'] ?? [])
            ->filter(fn (mixed $itemId): bool => is_string($itemId) && trim($itemId) !== '')
            ->values()
            ->all();

        return [
            'id' => $insight->id,
            'insight_type' => $insight->insight_type,
            'card_type' => $insight->card_type,
            'data' => $insight->data,
            'captured_at' => $insight->captured_at,
            'analysis_delivery_id' => is_int($metadata['analysis_delivery_id'] ?? null)
                ? $metadata['analysis_delivery_id']
                : null,
            'evidence_item_ids' => $evidenceItemIds,
        ];
    }
}
