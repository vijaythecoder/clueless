<?php

use App\Models\McpToolConfig;
use App\Models\Template;
use App\Services\ApiKeyService;
use Illuminate\Support\Facades\Http;
use Tests\Traits\MocksOpenAI;

uses(MocksOpenAI::class);

test('createClientSecret returns success with valid API key', function () {
    app(ApiKeyService::class)->setApiKey(mockApiKey());
    $this->mockRealtimeClientSecretSuccess();

    $response = $this->postJson('/api/realtime/client-secret', [
        'purpose' => 'copilot',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'clientSecret',
            'expiresAt',
            'session',
            'purpose',
        ])
        ->assertJson([
            'status' => 'success',
            'purpose' => 'copilot',
        ]);

    expect($response->json('clientSecret'))->toStartWith('ek_');
});

test('createClientSecret returns error when no API key configured', function () {
    Config::set('openai.api_key', null);

    $response = $this->postJson('/api/realtime/client-secret', [
        'purpose' => 'copilot',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'status' => 'error',
            'message' => 'OpenAI API key not configured',
        ]);
});

test('createClientSecret posts GA client secret payload for transcription', function () {
    app(ApiKeyService::class)->setApiKey(mockApiKey());

    Http::fake([
        'api.openai.com/v1/realtime/client_secrets' => function ($request) {
            $payload = $request->data();

            expect($payload['session']['type'])->toBe('transcription');
            expect($payload['session']['audio']['input']['transcription']['model'])->toBe('gpt-realtime-whisper');
            expect($payload['expires_after']['seconds'])->toBe(600);

            return Http::response(mockRealtimeClientSecretResponse([
                'session' => array_merge($payload['session'], ['id' => 'sess_transcription']),
            ]));
        },
    ]);

    $response = $this->postJson('/api/realtime/client-secret', [
        'purpose' => 'salesperson_transcription',
    ]);

    $response->assertOk()
        ->assertJsonPath('session.type', 'transcription');
});

test('createClientSecret includes configured MCP and UI tools for copilot', function () {
    app(ApiKeyService::class)->setApiKey(mockApiKey());

    McpToolConfig::create([
        'name' => 'Sales Docs',
        'server_label' => 'sales_docs',
        'server_url' => 'https://example.com/mcp',
        'allowed_tools' => ['search_docs'],
        'require_approval' => 'never',
        'is_enabled' => true,
        'is_read_only' => true,
    ]);

    Http::fake([
        'api.openai.com/v1/realtime/client_secrets' => function ($request) {
            $tools = collect($request->data()['session']['tools']);

            expect($tools->firstWhere('type', 'mcp')['server_label'])->toBe('sales_docs');
            expect($tools->firstWhere('name', 'show_knowledge_card'))->not->toBeNull();

            return Http::response(mockRealtimeClientSecretResponse([
                'session' => array_merge($request->data()['session'], ['id' => 'sess_copilot']),
            ]));
        },
    ]);

    $response = $this->postJson('/api/realtime/client-secret', [
        'purpose' => 'copilot',
    ]);

    $response->assertOk()
        ->assertJsonPath('session.type', 'realtime')
        ->assertJsonPath('session.model', 'gpt-realtime-2.1')
        ->assertJsonPath('mcpToolCount', 1);
});

test('createClientSecret resolves template instructions for copilot', function () {
    app(ApiKeyService::class)->setApiKey(mockApiKey());

    $template = Template::create([
        'name' => 'Discovery',
        'prompt' => 'Sell {product_name}.',
        'variables' => ['product_name' => 'Clueless'],
    ]);

    Http::fake([
        'api.openai.com/v1/realtime/client_secrets' => function ($request) {
            expect($request->data()['session']['instructions'])->toContain('Sell Clueless.');

            return Http::response(mockRealtimeClientSecretResponse([
                'session' => array_merge($request->data()['session'], ['id' => 'sess_template']),
            ]));
        },
    ]);

    $response = $this->postJson('/api/realtime/client-secret', [
        'purpose' => 'copilot',
        'template_id' => $template->id,
    ]);

    $response->assertOk();
});

test('createClientSecret returns error when OpenAI API fails', function () {
    app(ApiKeyService::class)->setApiKey(mockApiKey());
    $this->mockRealtimeClientSecretFailure();

    $response = $this->postJson('/api/realtime/client-secret', [
        'purpose' => 'copilot',
    ]);

    $response->assertStatus(500)
        ->assertJson([
            'status' => 'error',
            'message' => 'Failed to create Realtime client secret',
            'details' => [
                'type' => 'invalid_request_error',
                'code' => 'invalid_api_key',
            ],
        ]);
});

test('createClientSecret returns error when response structure is invalid', function () {
    app(ApiKeyService::class)->setApiKey(mockApiKey());
    $this->mockRealtimeClientSecretInvalidResponse();

    $response = $this->postJson('/api/realtime/client-secret', [
        'purpose' => 'copilot',
    ]);

    $response->assertStatus(500)
        ->assertJson([
            'status' => 'error',
            'message' => 'Failed to create Realtime client secret',
        ]);
});

test('createClientSecret validates purpose', function () {
    app(ApiKeyService::class)->setApiKey(mockApiKey());

    $response = $this->postJson('/api/realtime/client-secret', [
        'purpose' => 'legacy',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['purpose']);
});
