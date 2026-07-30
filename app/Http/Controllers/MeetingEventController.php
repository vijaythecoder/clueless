<?php

namespace App\Http\Controllers;

use App\Models\MeetingCaptureSession;
use App\Services\MeetingEventFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeetingEventController extends Controller
{
    public function __construct(
        private readonly MeetingEventFeed $feed,
    ) {}

    public function __invoke(Request $request, MeetingCaptureSession $capture): JsonResponse
    {
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
