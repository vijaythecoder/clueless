<?php

use App\Models\McpToolConfig;
use App\Services\SalesToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->registry = new SalesToolRegistry;
});

test('write-capable tool configs require approval', function () {
    expect(fn () => $this->registry->validateConfig([
        'name' => 'CRM Writer',
        'server_label' => 'crm_writer',
        'server_url' => 'https://example.com/mcp',
        'allowed_tools' => ['update_contact'],
        'require_approval' => 'never',
        'is_enabled' => true,
        'is_read_only' => false,
    ]))->toThrow(ValidationException::class);
});

test('existing write-capable configs are forced to require approval', function () {
    McpToolConfig::create([
        'name' => 'CRM Writer',
        'server_label' => 'crm_writer',
        'server_url' => 'https://example.com/mcp',
        'allowed_tools' => ['update_contact'],
        'require_approval' => 'never',
        'is_enabled' => true,
        'is_read_only' => false,
    ]);

    expect($this->registry->remoteTools())->toHaveCount(1)
        ->and($this->registry->remoteTools()[0]['require_approval'])->toBe('always');
});

test('empty allowed tools are rejected and never exposed', function () {
    expect(fn () => $this->registry->validateConfig([
        'name' => 'Unbounded Tools',
        'server_label' => 'unbounded',
        'server_url' => 'https://example.com/mcp',
        'allowed_tools' => [],
        'require_approval' => 'always',
        'is_enabled' => true,
        'is_read_only' => true,
    ]))->toThrow(ValidationException::class);

    McpToolConfig::create([
        'name' => 'Legacy Unbounded Tools',
        'server_label' => 'legacy_unbounded',
        'server_url' => 'https://example.com/mcp',
        'allowed_tools' => [],
        'require_approval' => 'always',
        'is_enabled' => true,
        'is_read_only' => true,
    ]);

    expect($this->registry->remoteTools())->toBe([]);
});

test('duplicate authorization sources are rejected case insensitively', function () {
    expect(fn () => $this->registry->validateConfig([
        'name' => 'Ambiguous Auth',
        'server_label' => 'ambiguous_auth',
        'server_url' => 'https://example.com/mcp',
        'authorization' => 'oauth-token',
        'headers' => ['aUtHoRiZaTiOn' => 'Bearer another-token'],
        'allowed_tools' => ['search'],
        'require_approval' => 'never',
        'is_enabled' => true,
        'is_read_only' => true,
    ]))->toThrow(ValidationException::class);
});

test('legacy duplicate authorization configs are not exposed to realtime', function () {
    McpToolConfig::create([
        'name' => 'Ambiguous Auth',
        'server_label' => 'ambiguous_auth',
        'server_url' => 'https://example.com/mcp',
        'authorization' => 'oauth-token',
        'headers' => ['Authorization' => 'Bearer another-token'],
        'allowed_tools' => ['search'],
        'require_approval' => 'never',
        'is_enabled' => true,
        'is_read_only' => true,
    ]);

    expect($this->registry->remoteTools())->toBe([]);
});

test('public configs preserve renderer secrecy', function () {
    McpToolConfig::create([
        'name' => 'Private Docs',
        'server_label' => 'private_docs',
        'server_url' => 'https://example.com/mcp',
        'authorization' => 'oauth-secret-token',
        'headers' => ['X-Private-Key' => 'private-header-secret'],
        'allowed_tools' => ['search'],
        'require_approval' => 'never',
        'is_enabled' => true,
        'is_read_only' => true,
    ]);

    $config = $this->registry->publicConfigs()->first();
    $encoded = json_encode($config);

    expect($config)
        ->not->toHaveKeys(['authorization', 'headers'])
        ->and($config['has_authorization'])->toBeTrue()
        ->and($config['has_headers'])->toBeTrue()
        ->and($encoded)->not->toContain('oauth-secret-token')
        ->and($encoded)->not->toContain('private-header-secret');
});

test('UI function tools use closed schemas supported by Realtime', function () {
    foreach ($this->registry->uiTools() as $tool) {
        expect($tool)->not->toHaveKey('strict')
            ->and($tool['parameters']['additionalProperties'])->toBeFalse()
            ->and($tool['parameters']['required'])->toBe(array_keys($tool['parameters']['properties']));
    }
});

it('limits talk tracks to material moments in the tool contract', function () {
    $talkTrack = collect($this->registry->uiTools())->firstWhere('name', 'suggest_talk_track');

    expect($talkTrack['description'])
        ->toContain('only when an immediate response')
        ->toContain('would materially help');
});

it('registers exact pain point and topic schemas', function () {
    $tools = collect($this->registry->uiTools())->keyBy('name');

    expect($tools['capture_pain_point'])->toBe([
        'type' => 'function',
        'name' => 'capture_pain_point',
        'description' => 'Capture an evidence-backed customer pain point.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string', 'minLength' => 1, 'pattern' => '\S'],
                'category' => [
                    'type' => ['string', 'null'],
                    'minLength' => 1,
                    'pattern' => '\S',
                ],
                'severity' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                'evidence_item_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'minLength' => 1, 'pattern' => '\S'],
                    'minItems' => 1,
                    'uniqueItems' => true,
                ],
            ],
            'required' => ['text', 'category', 'severity', 'evidence_item_ids'],
            'additionalProperties' => false,
        ],
    ])->and($tools['capture_discussion_topic'])->toBe([
        'type' => 'function',
        'name' => 'capture_discussion_topic',
        'description' => 'Capture an evidence-backed discussion topic.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'pattern' => '\S'],
                'sentiment' => [
                    'type' => 'string',
                    'enum' => ['positive', 'negative', 'neutral', 'mixed'],
                ],
                'context' => ['type' => 'string', 'minLength' => 1, 'pattern' => '\S'],
                'evidence_item_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'minLength' => 1, 'pattern' => '\S'],
                    'minItems' => 1,
                    'uniqueItems' => true,
                ],
            ],
            'required' => ['name', 'sentiment', 'context', 'evidence_item_ids'],
            'additionalProperties' => false,
        ],
    ]);
});

it('uses schema patterns that reject whitespace-only evidence card strings', function () {
    $tools = collect($this->registry->uiTools())->keyBy('name');
    $properties = [
        $tools['capture_pain_point']['parameters']['properties']['text'],
        $tools['capture_pain_point']['parameters']['properties']['category'],
        $tools['capture_pain_point']['parameters']['properties']['evidence_item_ids']['items'],
        $tools['capture_discussion_topic']['parameters']['properties']['name'],
        $tools['capture_discussion_topic']['parameters']['properties']['context'],
        $tools['capture_discussion_topic']['parameters']['properties']['evidence_item_ids']['items'],
    ];

    foreach ($properties as $property) {
        expect(preg_match('/'.$property['pattern'].'/u', " \t\n"))->toBe(0);
    }

    expect($tools['capture_pain_point']['parameters']['properties']['category']['type'])
        ->toBe(['string', 'null']);
});

test('server labels are unique across all configs and ignore the current model on update', function () {
    $existing = McpToolConfig::create([
        'name' => 'Existing',
        'server_label' => 'shared_label',
        'server_url' => 'https://example.com/mcp',
        'allowed_tools' => ['search'],
        'require_approval' => 'never',
        'is_enabled' => false,
        'is_read_only' => true,
    ]);

    $payload = [
        'name' => 'Duplicate',
        'server_label' => 'shared_label',
        'server_url' => 'https://example.net/mcp',
        'allowed_tools' => ['search'],
        'require_approval' => 'never',
        'is_enabled' => true,
        'is_read_only' => true,
    ];

    expect(fn () => $this->registry->validateConfig($payload))
        ->toThrow(ValidationException::class)
        ->and($this->registry->validateConfig($payload, $existing)['server_label'])
        ->toBe('shared_label');
});
