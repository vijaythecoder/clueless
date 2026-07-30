<?php

namespace App\Jobs;

use App\Models\MeetingAnalysisDelivery;
use App\Models\MeetingCaptureSession;
use App\Services\MeetingAnalysisDeliveryService;
use App\Services\RecallCopilotAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AnalyzeRecallCapture implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 55;

    public int $tries = 1;

    public function __construct(public readonly string $captureId)
    {
        $this->onQueue('recall-analysis');
        $this->afterCommit();
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("recall-analysis:{$this->captureId}"))
                ->releaseAfter(1)
                ->expireAfter(60),
        ];
    }

    public function handle(
        MeetingAnalysisDeliveryService $deliveries,
        RecallCopilotAnalysisService $analysis,
    ): void {
        if (config('openai.recall_analysis.driver') !== 'responses') {
            return;
        }

        $capture = MeetingCaptureSession::query()->find($this->captureId);

        if (! $capture) {
            return;
        }

        do {
            $claim = $deliveries->claim($capture, PHP_INT_MAX);

            foreach ($claim['deliveries'] as $claimed) {
                $delivery = MeetingAnalysisDelivery::query()->find($claimed['id']);

                if (! $delivery) {
                    continue;
                }

                try {
                    $analysis->analyze($capture, $delivery, $claimed['lease_token']);
                } catch (Throwable $exception) {
                    Log::warning('Recall Responses analysis failed', [
                        'capture_id' => $capture->id,
                        'delivery_id' => $delivery->id,
                        'error_type' => $exception::class,
                    ]);

                    $delivery->refresh();
                    if ($delivery->status->value === 'pending') {
                        self::dispatch($capture->id)
                            ->delay(now()->addSeconds(min(8, 2 ** $delivery->attempts)));

                        return;
                    }
                }
            }
        } while ($claim['deliveries'] !== []);
    }
}
