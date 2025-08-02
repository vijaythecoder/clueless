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
     * Mock a successful ephemeral key generation response
     */
    protected function mockEphemeralKeySuccess(): void
    {
        Http::fake([
            'api.openai.com/v1/realtime/sessions' => Http::response(mockEphemeralKeyResponse(), 200),
        ]);
    }

    /**
     * Mock a failed ephemeral key generation response
     */
    protected function mockEphemeralKeyFailure(): void
    {
        Http::fake([
            'api.openai.com/v1/realtime/sessions' => Http::response([
                'error' => [
                    'message' => 'Invalid API key',
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_api_key',
                ],
            ], 401),
        ]);
    }

    /**
     * Mock an invalid ephemeral key response structure
     */
    protected function mockEphemeralKeyInvalidResponse(): void
    {
        Http::fake([
            'api.openai.com/v1/realtime/sessions' => Http::response([
                'id' => 'sess_123',
                'model' => 'gpt-4o-mini-realtime-preview-2024-12-17',
                // Missing client_secret structure
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
