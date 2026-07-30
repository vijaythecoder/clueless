<?php

namespace App\Http\Controllers;

use App\Exceptions\MissingOpenAIKeyException;
use App\Exceptions\RealtimeClientSecretException;
use App\Http\Requests\RealtimeClientSecretRequest;
use App\Services\OpenAIRealtimeService;
use Illuminate\Support\Facades\Log;

class RealtimeController extends Controller
{
    public function __construct(private OpenAIRealtimeService $openAIRealtimeService) {}

    /**
     * Create a GA Realtime client secret for browser/Electron usage.
     */
    public function createClientSecret(RealtimeClientSecretRequest $request)
    {
        return $this->issueClientSecret($request->validated());
    }

    private function issueClientSecret(array $validated)
    {
        try {
            $data = $this->openAIRealtimeService->createClientSecret(
                $validated['purpose'],
                $validated['template_id'] ?? null,
                $validated['context'] ?? [],
            );

            return response()->json([
                'status' => 'success',
                ...$data,
            ]);
        } catch (MissingOpenAIKeyException) {
            return response()->json([
                'status' => 'error',
                'message' => 'OpenAI API key not configured',
            ], 422);
        } catch (RealtimeClientSecretException $e) {
            Log::error('Failed to create Realtime client secret', [
                'message' => $e->getMessage(),
                'status' => $e->statusCode(),
                'payload' => $e->payload(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create Realtime client secret',
                'details' => $e->payload()['error'] ?? ['type' => 'upstream_error'],
            ], 500);
        } catch (\Throwable $e) {
            Log::error('Unexpected Realtime client secret failure', [
                'exception' => $e::class,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create Realtime client secret',
            ], 500);
        }
    }
}
