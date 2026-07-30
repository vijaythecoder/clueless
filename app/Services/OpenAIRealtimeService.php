<?php

namespace App\Services;

use App\Exceptions\MissingOpenAIKeyException;
use App\Exceptions\RealtimeClientSecretException;
use App\Models\Template;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class OpenAIRealtimeService
{
    public function __construct(
        private ApiKeyService $apiKeyService,
        private CopilotSessionService $copilotSessionService,
    ) {}

    public function createClientSecret(string $purpose, ?string $templateId = null, array $context = []): array
    {
        $apiKey = $this->apiKeyService->getApiKey();

        if (! $apiKey) {
            throw new MissingOpenAIKeyException;
        }

        $template = $templateId ? Template::find($templateId) : null;
        $session = $this->copilotSessionService->build($purpose, $template, $context);
        $mcpToolCount = collect($session['tools'] ?? [])
            ->where('type', 'mcp')
            ->count();

        $response = $this->client($apiKey)
            ->post($this->clientSecretsUrl(), [
                'expires_after' => [
                    'anchor' => 'created_at',
                    'seconds' => config('openai.realtime.client_secret_ttl'),
                ],
                'session' => $session,
            ]);

        if (! $response->successful()) {
            throw new RealtimeClientSecretException(
                'OpenAI client secret request failed.',
                $response->status(),
                $this->sanitizedError($response->json()),
            );
        }

        return [
            ...$this->rendererSafeClientSecret(
                $response->json(),
                'OpenAI client secret response was missing required fields.',
            ),
            'purpose' => $purpose,
            'mcpToolCount' => $mcpToolCount,
        ];
    }

    public function createMcpToolTestClientSecret(array $tool): array
    {
        $apiKey = $this->apiKeyService->getApiKey();

        if (! $apiKey) {
            throw new MissingOpenAIKeyException;
        }

        $response = $this->client(
            $apiKey,
            timeout: config('openai.realtime.tool_test_timeout'),
            connectTimeout: config('openai.realtime.tool_test_connect_timeout'),
            retries: 0,
        )->post($this->clientSecretsUrl(), [
            'expires_after' => [
                'anchor' => 'created_at',
                'seconds' => config('openai.realtime.tool_test_client_secret_ttl'),
            ],
            'session' => [
                'type' => 'realtime',
                'model' => config('openai.realtime.copilot_model'),
                'output_modalities' => ['text'],
                'instructions' => 'Validate the configured MCP tool for this session.',
                'tools' => [$tool],
                'tool_choice' => 'none',
                'max_output_tokens' => 1,
                'truncation' => 'disabled',
            ],
        ]);

        if (! $response->successful()) {
            throw new RealtimeClientSecretException(
                'OpenAI rejected the sales tool configuration.',
                $response->status(),
                $this->sanitizedError($response->json()),
            );
        }

        return $this->rendererSafeClientSecret(
            $response->json(),
            'OpenAI returned an invalid sales tool test response.',
        );
    }

    private function client(
        string $apiKey,
        ?int $timeout = null,
        ?int $connectTimeout = null,
        ?int $retries = null,
    ): PendingRequest {
        $retryCount = $retries ?? config('openai.realtime.retries');

        return Http::timeout($timeout ?? config('openai.realtime.timeout'))
            ->connectTimeout($connectTimeout ?? config('openai.realtime.connect_timeout'))
            ->retry(
                $retryCount + 1,
                config('openai.realtime.retry_delay_ms'),
                function (\Exception $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if (! $exception instanceof RequestException) {
                        return false;
                    }

                    return $exception->response->status() === 429
                        || $exception->response->serverError();
                },
                throw: false,
            )
            ->withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
                'OpenAI-Safety-Identifier' => $this->safetyIdentifier(),
            ]);
    }

    private function clientSecretsUrl(): string
    {
        return rtrim(config('openai.realtime.base_url'), '/').'/realtime/client_secrets';
    }

    private function safetyIdentifier(): string
    {
        $appKey = (string) config('app.key', 'clueless-desktop');
        $seed = (string) config('openai.realtime.safety_identifier_seed', 'clueless-desktop');

        return hash_hmac('sha256', $seed, $appKey);
    }

    private function rendererSafeClientSecret(mixed $data, string $invalidMessage): array
    {
        if (
            ! is_array($data)
            || ! is_string($data['value'] ?? null)
            || ! str_starts_with($data['value'], 'ek_')
            || ! isset($data['expires_at'])
            || ! is_array($data['session'] ?? null)
        ) {
            throw new RealtimeClientSecretException(
                $invalidMessage,
                500,
                ['error' => ['type' => 'invalid_upstream_response']],
            );
        }

        return [
            'clientSecret' => $data['value'],
            'expiresAt' => $data['expires_at'],
            'session' => Arr::only($data['session'], [
                'id',
                'object',
                'type',
                'model',
                'output_modalities',
                'expires_at',
            ]),
        ];
    }

    private function sanitizedError(mixed $payload): array
    {
        if (! is_array($payload) || ! is_array($payload['error'] ?? null)) {
            return ['error' => ['type' => 'upstream_error']];
        }

        return [
            'error' => Arr::only($payload['error'], [
                'type',
                'code',
                'param',
            ]),
        ];
    }
}
