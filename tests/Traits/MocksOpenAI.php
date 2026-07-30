<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\Http;

trait MocksOpenAI
{
    /**
     * Mock a successful OpenAI models list response for API key validation
     */
    protected function mockOpenAIModelsSuccess(): void
    {
        Http::fake([
            'api.openai.com/v1/models' => Http::response([
                'object' => 'list',
                'data' => [
                    ['id' => 'gpt-4', 'object' => 'model'],
                    ['id' => 'gpt-3.5-turbo', 'object' => 'model'],
                ],
            ], 200),
        ]);
    }

    /**
     * Mock a failed OpenAI models list response for API key validation
     */
    protected function mockOpenAIModelsFailure(): void
    {
        Http::fake([
            'api.openai.com/v1/models' => Http::response([
                'error' => [
                    'message' => 'Invalid API key provided',
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_api_key',
                ],
            ], 401),
        ]);
    }

    /**
     * Mock a successful Realtime client secret response.
     */
    protected function mockRealtimeClientSecretSuccess(): void
    {
        Http::fake([
            'api.openai.com/v1/realtime/client_secrets' => Http::response(mockRealtimeClientSecretResponse(), 200),
        ]);
    }

    /**
     * Mock a failed Realtime client secret response.
     */
    protected function mockRealtimeClientSecretFailure(): void
    {
        Http::fake([
            'api.openai.com/v1/realtime/client_secrets' => Http::response([
                'error' => [
                    'message' => 'Invalid API key',
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_api_key',
                ],
            ], 401),
        ]);
    }

    /**
     * Mock an invalid Realtime client secret response structure.
     */
    protected function mockRealtimeClientSecretInvalidResponse(): void
    {
        Http::fake([
            'api.openai.com/v1/realtime/client_secrets' => Http::response([
                'id' => 'sess_123',
                'model' => 'gpt-realtime-2.1',
                // Missing GA client secret response structure
            ], 200),
        ]);
    }

    /**
     * Mock HTTP timeout
     */
    protected function mockHttpTimeout(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        });
    }
}
