<?php

namespace App\Http\Controllers;

use App\Models\MeetingCaptureSession;
use App\Services\RecallCopilotInsightFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeetingInsightController extends Controller
{
    public function __construct(
        private readonly RecallCopilotInsightFeed $feed,
    ) {}

    public function __invoke(Request $request, MeetingCaptureSession $capture): JsonResponse
    {
        abort_unless(config('openai.recall_analysis.driver') === 'responses', 404);
        $validated = $request->validate([
            'after' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer'],
        ]);

        return response()->json($this->feed->page(
            $capture,
            (int) ($validated['after'] ?? 0),
            (int) ($validated['limit'] ?? 100),
        ));
    }
}
