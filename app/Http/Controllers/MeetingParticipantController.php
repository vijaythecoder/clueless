<?php

namespace App\Http\Controllers;

use App\Enums\SalesRole;
use App\Http\Requests\AssignMeetingParticipantRequest;
use App\Jobs\AnalyzeRecallCapture;
use App\Models\MeetingCaptureSession;
use App\Models\MeetingParticipant;
use App\Services\MeetingEventFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MeetingParticipantController extends Controller
{
    public function __construct(
        private readonly MeetingEventFeed $feed,
    ) {}

    public function update(
        AssignMeetingParticipantRequest $request,
        MeetingCaptureSession $capture,
        MeetingParticipant $participant,
    ): JsonResponse {
        if ($participant->meeting_capture_session_id !== $capture->id) {
            throw new NotFoundHttpException;
        }

        if ($participant->is_bot) {
            throw ValidationException::withMessages([
                'sales_role' => 'A bot cannot be assigned as the salesperson.',
            ]);
        }

        $participants = DB::transaction(function () use ($capture, $participant) {
            $participants = $capture->participants()->lockForUpdate()->orderBy('id')->get();

            foreach ($participants as $candidate) {
                $role = $candidate->is_bot
                    ? SalesRole::Bot
                    : ($candidate->id === $participant->id ? SalesRole::Salesperson : SalesRole::Customer);

                $candidate->forceFill(['sales_role' => $role])->save();
                $candidate->transcripts()
                    ->where('meeting_capture_session_id', $capture->id)
                    ->update(['speaker' => $role->value]);
            }

            return $participants->map->refresh();
        });

        if (config('openai.recall_analysis.driver') === 'responses') {
            AnalyzeRecallCapture::dispatch($capture->id);
        }

        return response()->json([
            'participants' => $participants
                ->map(fn (MeetingParticipant $item): array => $this->feed->serializeParticipant($item))
                ->values()
                ->all(),
        ]);
    }
}
