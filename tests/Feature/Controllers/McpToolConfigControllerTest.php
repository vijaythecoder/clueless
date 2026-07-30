<?php

use App\Models\McpToolConfig;
use App\Services\ApiKeyService;
use Illuminate\Support\Facades\Http;

test('sales tool test issues a short-lived client secret without exposing long-lived credentials', function () {
    app(ApiKeyService::class)->setApiKey(mockApiKey());

    config()->set([
        'openai.realtime.tool_test_timeout' => 3,
        'openai.realtime.tool_test_connect_timeout' => 2,
        'openai.realtime.tool_test_client_secret_ttl' => 60,
    ]);

    $tool = McpToolConfig::create([
        'name' => 'Private Sales Docs',
        'server_label' => 'private_sales_docs',
        'server_url' => 'https://example.com/mcp',
        'authorization' => 'oauth-secret-token',
        'headers' => ['X-Private-Key' => 'private-header-secret'],
        'allowed_tools' => ['search_docs'],
        'require_approval' => 'never',
        'is_enabled' => true,
        'is_read_only' => true,
    ]);

    $seenOptions = [];
    Http::globalMiddleware(function (callable $handler) use (&$seenOptions) {
        return function ($request, array $options) use ($handler, &$seenOptions) {
            $seenOptions = $options;

            return $handler($request, $options);
        };
    });

    Http::fake([
        'api.openai.com/v1/realtime/client_secrets' => function ($request) {
            $configuredTool = $request->data()['session']['tools'][0];

            expect($configuredTool['authorization'])->toBe('oauth-secret-token')
                ->and($configuredTool['headers']['X-Private-Key'])->toBe('private-header-secret')
                ->and($configuredTool['allowed_tools'])->toBe(['search_docs'])
                ->and($request->data()['expires_after']['seconds'])->toBe(60);

            return Http::response(mockRealtimeClientSecretResponse([
                'value' => 'ek_test_secret',
                'expires_at' => 1735689600,
                'session' => [
                    'id' => 'sess_test',
                ],
            ]));
        },
    ]);

    $response = $this->postJson("/api/sales-tools/{$tool->id}/test");

    $response->assertOk()
        ->assertExactJson([
            'clientSecret' => 'ek_test_secret',
            'expiresAt' => 1735689600,
            'session' => [
                'id' => 'sess_test',
                'model' => 'gpt-realtime-2.1',
                'object' => 'realtime.session',
                'output_modalities' => ['text'],
                'type' => 'realtime',
            ],
        ]);

    expect($seenOptions['timeout'])->toBe(3)
        ->and($seenOptions['connect_timeout'])->toBe(2)
        ->and($response->json('clientSecret'))->toStartWith('ek_')
        ->and($response->getContent())->not->toContain(mockApiKey())
        ->and($response->getContent())->not->toContain('oauth-secret-token')
        ->and($response->getContent())->not->toContain('private-header-secret');
});

test('sales tool test returns a concise sanitized upstream failure', function () {
    app(ApiKeyService::class)->setApiKey(mockApiKey());

    $tool = McpToolConfig::create([
        'name' => 'Rejected Tool',
        'server_label' => 'rejected_tool',
        'server_url' => 'https://example.com/mcp',
        'authorization' => 'oauth-secret-token',
        'allowed_tools' => ['search_docs'],
        'require_approval' => 'never',
        'is_enabled' => true,
        'is_read_only' => true,
    ]);

    Http::fake([
        '*' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Rejected oauth-secret-token',
            ],
        ], 400),
    ]);

    $response = $this->postJson("/api/sales-tools/{$tool->id}/test");

    $response->assertStatus(502)
        ->assertExactJson([
            'success' => false,
            'message' => 'Unable to prepare this sales tool test.',
        ]);

    expect($response->getContent())->not->toContain('oauth-secret-token');
});

test('sales tool test rejects unsafe saved configs before making a request', function () {
    app(ApiKeyService::class)->setApiKey(mockApiKey());

    $tool = McpToolConfig::create([
        'name' => 'Unbounded Tool',
        'server_label' => 'unbounded_tool',
        'server_url' => 'https://example.com/mcp',
        'allowed_tools' => [],
        'require_approval' => 'always',
        'is_enabled' => true,
        'is_read_only' => true,
    ]);

    Http::fake();

    $this->postJson("/api/sales-tools/{$tool->id}/test")
        ->assertStatus(422)
        ->assertExactJson([
            'success' => false,
            'message' => 'This sales tool configuration is not safe to test.',
        ]);

    Http::assertNothingSent();
});

test('sales tool test requires an OpenAI API key', function () {
    config()->set('openai.api_key', null);

    $tool = McpToolConfig::create([
        'name' => 'Sales Docs',
        'server_label' => 'sales_docs',
        'server_url' => 'https://example.com/mcp',
        'allowed_tools' => ['search_docs'],
        'require_approval' => 'never',
        'is_enabled' => true,
        'is_read_only' => true,
    ]);

    $this->postJson("/api/sales-tools/{$tool->id}/test")
        ->assertStatus(422)
        ->assertExactJson([
            'success' => false,
            'message' => 'Configure an OpenAI API key before testing sales tools.',
        ]);
});
