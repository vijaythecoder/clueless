<?php

namespace App\Http\Controllers;

use App\Services\ApiKeyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RealtimeController extends Controller
{
    private ApiKeyService $apiKeyService;

    public function __construct(ApiKeyService $apiKeyService)
    {
        $this->apiKeyService = $apiKeyService;
    }

    /**
     * Generate ephemeral key for secure browser usage
     */
    public function generateEphemeralKey(Request $request)
    {
        try {
            $apiKey = $this->apiKeyService->getApiKey();

            if (! $apiKey) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'OpenAI API key not configured',
                ], 422);
            }

            // Generate ephemeral key from OpenAI Realtime API
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/realtime/sessions', [
                'model' => 'gpt-4o-mini-realtime-preview-2024-12-17',
                'voice' => $request->input('voice', 'alloy'),
            ]);

            if (! $response->successful()) {
                Log::error('OpenAI API error: '.$response->body());
                throw new \Exception('Failed to generate ephemeral key from OpenAI: '.$response->status());
            }

            $data = $response->json();

            // Validate response structure
            if (! isset($data['client_secret']['value']) || ! isset($data['client_secret']['expires_at'])) {
                Log::error('Invalid response structure from OpenAI API', ['response' => $data]);
                throw new \Exception('Invalid response structure from OpenAI API');
            }

            // Return ephemeral key data
            return response()->json([
                'status' => 'success',
                'ephemeralKey' => $data['client_secret']['value'],
                'expiresAt' => $data['client_secret']['expires_at'],
                'sessionId' => $data['id'] ?? null,
                'model' => $data['model'] ?? 'gpt-4o-mini-realtime-preview-2024-12-17',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to generate ephemeral key: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate ephemeral key',
            ], 500);
        }
    }
}
