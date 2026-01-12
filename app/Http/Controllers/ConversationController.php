<?php

namespace App\Http\Controllers;

use App\Models\ConversationSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ConversationController extends Controller
{
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
        // No auth check needed for single-user desktop app

        $session->load(['transcripts', 'insights']);

        return Inertia::render('Conversations/Show', [
            'session' => $session,
            'transcripts' => $session->transcripts,
            'insights' => $session->insights->groupBy('insight_type'),
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
        ]);

        // No user ID needed for single-user desktop app
        $session = ConversationSession::create([
            'user_id' => null,
            'started_at' => now(),
            'template_used' => $validated['template_used'] ?? null,
            'customer_name' => $validated['customer_name'] ?? null,
            'customer_company' => $validated['customer_company'] ?? null,
        ]);

        return response()->json([
            'session_id' => $session->id,
            'message' => 'Session started successfully',
        ]);
    }

    /**
     * End a conversation session.
     */
    public function end(ConversationSession $session, Request $request)
    {
        // No auth check needed for single-user desktop app

        $validated = $request->validate([
            'duration_seconds' => 'required|integer',
            'final_intent' => 'nullable|string',
            'final_buying_stage' => 'nullable|string',
            'final_engagement_level' => 'nullable|integer',
            'final_sentiment' => 'nullable|string',
            'ai_summary' => 'nullable|string',
        ]);

        $session->update([
            'ended_at' => now(),
            'duration_seconds' => $validated['duration_seconds'],
            'final_intent' => $validated['final_intent'] ?? null,
            'final_buying_stage' => $validated['final_buying_stage'] ?? null,
            'final_engagement_level' => $validated['final_engagement_level'] ?? 50,
            'final_sentiment' => $validated['final_sentiment'] ?? null,
            'ai_summary' => $validated['ai_summary'] ?? null,
        ]);

        // Update counts
        $session->update([
            'total_transcripts' => $session->transcripts()->count(),
            'total_insights' => $session->insights()->where('insight_type', 'key_insight')->count(),
            'total_topics' => $session->insights()->where('insight_type', 'topic')->count(),
            'total_commitments' => $session->insights()->where('insight_type', 'commitment')->count(),
            'total_action_items' => $session->insights()->where('insight_type', 'action_item')->count(),
        ]);

        return response()->json([
            'message' => 'Session ended successfully',
        ]);
    }

    /**
     * Save a transcript to the session.
     */
    public function saveTranscript(ConversationSession $session, Request $request)
    {
        // No auth check needed for single-user desktop app

        $validated = $request->validate([
            'speaker' => 'required|in:salesperson,customer,system',
            'text' => 'required|string',
            'spoken_at' => 'required|integer',
            'group_id' => 'nullable|string',
            'system_category' => 'nullable|string',
        ]);

        // Get the next order index
        $nextOrder = $session->transcripts()->max('order_index') + 1;

        $transcript = $session->transcripts()->create([
            'speaker' => $validated['speaker'],
            'text' => $validated['text'],
            'spoken_at' => \Carbon\Carbon::createFromTimestampMs($validated['spoken_at']),
            'group_id' => $validated['group_id'] ?? null,
            'system_category' => $validated['system_category'] ?? null,
            'order_index' => $nextOrder,
        ]);

        return response()->json([
            'transcript_id' => $transcript->id,
            'message' => 'Transcript saved successfully',
        ]);
    }

    /**
     * Save batch transcripts.
     */
    public function saveBatchTranscripts(ConversationSession $session, Request $request)
    {
        // No auth check needed for single-user desktop app

        $validated = $request->validate([
            'transcripts' => 'required|array',
            'transcripts.*.speaker' => 'required|in:salesperson,customer,system',
            'transcripts.*.text' => 'required|string',
            'transcripts.*.spoken_at' => 'required|integer',
            'transcripts.*.group_id' => 'nullable|string',
            'transcripts.*.system_category' => 'nullable|string',
        ]);

        // Get the starting order index
        $startOrder = $session->transcripts()->max('order_index') ?? 0;

        DB::transaction(function () use ($session, $validated, $startOrder) {
            foreach ($validated['transcripts'] as $index => $transcriptData) {
                $session->transcripts()->create([
                    'speaker' => $transcriptData['speaker'],
                    'text' => $transcriptData['text'],
                    'spoken_at' => \Carbon\Carbon::createFromTimestampMs($transcriptData['spoken_at']),
                    'group_id' => $transcriptData['group_id'] ?? null,
                    'system_category' => $transcriptData['system_category'] ?? null,
                    'order_index' => $startOrder + $index + 1,
                ]);
            }
        });

        return response()->json([
            'message' => 'Transcripts saved successfully',
        ]);
    }

    /**
     * Save an insight to the session.
     */
    public function saveInsight(ConversationSession $session, Request $request)
    {
        // No auth check needed for single-user desktop app

        $validated = $request->validate([
            'insight_type' => 'required|string',
            'data' => 'required|array',
            'captured_at' => 'required|integer',
        ]);

        $insight = $session->insights()->create([
            'insight_type' => $validated['insight_type'],
            'data' => $validated['data'],
            'captured_at' => \Carbon\Carbon::createFromTimestampMs($validated['captured_at']),
        ]);

        return response()->json([
            'insight_id' => $insight->id,
            'message' => 'Insight saved successfully',
        ]);
    }

    /**
     * Save batch insights.
     */
    public function saveBatchInsights(ConversationSession $session, Request $request)
    {
        // No auth check needed for single-user desktop app

        $validated = $request->validate([
            'insights' => 'required|array',
            'insights.*.insight_type' => 'required|string',
            'insights.*.data' => 'required|array',
            'insights.*.captured_at' => 'required|integer',
        ]);

        DB::transaction(function () use ($session, $validated) {
            foreach ($validated['insights'] as $insightData) {
                $session->insights()->create([
                    'insight_type' => $insightData['insight_type'],
                    'data' => $insightData['data'],
                    'captured_at' => \Carbon\Carbon::createFromTimestampMs($insightData['captured_at']),
                ]);
            }
        });

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
     * Update recording information for a conversation session.
     */
    public function updateRecording(ConversationSession $session, Request $request)
    {
        // No auth check needed for single-user desktop app
        
        $validated = $request->validate([
            'has_recording' => 'required|boolean',
            'recording_path' => 'required|string',
            'recording_duration' => 'required|integer|min:0',
            'recording_size' => 'required|integer|min:0',
        ]);
        
        // Validate that the recording file actually exists
        if ($validated['has_recording'] && $validated['recording_path']) {
            if (!file_exists($validated['recording_path'])) {
                return response()->json([
                    'message' => 'Recording file not found at specified path',
                ], 422);
            }
            
            // Verify file size matches
            $actualSize = filesize($validated['recording_path']);
            if ($actualSize !== $validated['recording_size']) {
                \Log::warning('Recording file size mismatch', [
                    'session_id' => $session->id,
                    'expected_size' => $validated['recording_size'],
                    'actual_size' => $actualSize,
                ]);
            }
        }
        
        $session->update($validated);
        
        return response()->json([
            'message' => 'Recording information updated successfully',
        ]);
    }
}
