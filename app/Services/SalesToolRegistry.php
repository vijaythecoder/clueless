<?php

namespace App\Services;

use App\Models\McpToolConfig;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesToolRegistry
{
    public function remoteTools(): array
    {
        return McpToolConfig::query()
            ->where('is_enabled', true)
            ->orderBy('name')
            ->get()
            ->map(fn (McpToolConfig $config) => $this->toRealtimeTool($config))
            ->filter()
            ->values()
            ->all();
    }

    public function uiTools(): array
    {
        return [
            $this->functionTool('show_knowledge_card', 'Show a relevant sales knowledge card in the copilot UI.', [
                'title' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'source' => ['type' => 'string'],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ], ['title', 'content']),
            $this->functionTool(
                'suggest_talk_track',
                'Suggest one concise talk track only when an immediate response to a customer question, objection, correction, risk, or stalled next step would materially help.',
                [
                    'text' => ['type' => 'string'],
                    'reason' => ['type' => 'string'],
                    'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                ],
                ['text'],
            ),
            $this->functionTool('capture_objection', 'Capture a customer objection or concern.', [
                'text' => ['type' => 'string'],
                'category' => ['type' => 'string'],
                'severity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
            ], ['text']),
            $this->functionTool('capture_pain_point', 'Capture an evidence-backed customer pain point.', [
                'text' => ['type' => 'string', 'minLength' => 1, 'pattern' => '\S'],
                'category' => ['type' => 'string', 'minLength' => 1, 'pattern' => '\S'],
                'severity' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                'evidence_item_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'minLength' => 1, 'pattern' => '\S'],
                    'minItems' => 1,
                    'uniqueItems' => true,
                ],
            ], ['text', 'severity', 'evidence_item_ids']),
            $this->functionTool('capture_discussion_topic', 'Capture an evidence-backed discussion topic.', [
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
            ], ['name', 'sentiment', 'context', 'evidence_item_ids']),
            $this->functionTool('capture_commitment', 'Capture a commitment made by the salesperson or customer.', [
                'speaker' => ['type' => 'string', 'enum' => ['salesperson', 'customer']],
                'text' => ['type' => 'string'],
                'deadline' => ['type' => 'string'],
            ], ['speaker', 'text']),
            $this->functionTool('create_follow_up', 'Create a post-call follow-up action item.', [
                'text' => ['type' => 'string'],
                'owner' => ['type' => 'string', 'enum' => ['salesperson', 'customer', 'both']],
                'deadline' => ['type' => 'string'],
            ], ['text', 'owner']),
            $this->functionTool('update_customer_intelligence', 'Update customer intent, stage, sentiment, and engagement.', [
                'intent' => ['type' => 'string', 'enum' => ['research', 'evaluation', 'decision', 'implementation', 'unknown']],
                'buyingStage' => ['type' => 'string'],
                'sentiment' => ['type' => 'string', 'enum' => ['positive', 'negative', 'neutral']],
                'engagementLevel' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
            ], []),
        ];
    }

    public function allTools(): array
    {
        return array_values(array_merge($this->remoteTools(), $this->uiTools()));
    }

    /**
     * Responses analysis currently executes without a suspended approval
     * workflow, so only explicitly approval-free read tools are exposed.
     */
    public function recallResponsesTools(): array
    {
        $remoteTools = McpToolConfig::query()
            ->where('is_enabled', true)
            ->where('is_read_only', true)
            ->where('require_approval', 'never')
            ->orderBy('name')
            ->get()
            ->map(fn (McpToolConfig $config) => $this->toRealtimeTool($config))
            ->filter()
            ->values()
            ->all();

        return array_values(array_merge($remoteTools, $this->uiTools()));
    }

    public function realtimeTool(McpToolConfig $config): ?array
    {
        return $this->toRealtimeTool($config);
    }

    public function validateConfig(array $data, ?McpToolConfig $ignore = null): array
    {
        $validator = validator($data, [
            'name' => 'required|string|max:255',
            'server_label' => [
                'required',
                'string',
                'max:255',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('mcp_tool_configs', 'server_label')->ignore($ignore?->getKey()),
            ],
            'server_url' => 'nullable|url|required_without:connector_id',
            'connector_id' => 'nullable|string|max:255|required_without:server_url',
            'authorization' => 'nullable|string',
            'headers' => 'nullable|array',
            'allowed_tools' => 'required|array|min:1',
            'allowed_tools.*' => 'string',
            'require_approval' => 'required|in:always,never',
            'is_enabled' => 'boolean',
            'is_read_only' => 'boolean',
            'metadata' => 'nullable|array',
        ]);

        $validated = $validator->validate();

        if (! empty($validated['server_url']) && ! empty($validated['connector_id'])) {
            throw ValidationException::withMessages([
                'server_url' => 'Use either server_url or connector_id, not both.',
            ]);
        }

        if (! ($validated['is_read_only'] ?? true) && $validated['require_approval'] !== 'always') {
            throw ValidationException::withMessages([
                'require_approval' => 'Write-capable tools must always require approval.',
            ]);
        }

        if (! empty($validated['authorization']) && $this->hasAuthorizationHeader($validated['headers'] ?? [])) {
            throw ValidationException::withMessages([
                'authorization' => 'Use either authorization or headers.Authorization, not both.',
            ]);
        }

        return $validated;
    }

    public function publicConfigs(): Collection
    {
        return McpToolConfig::query()
            ->orderBy('name')
            ->get()
            ->map(fn (McpToolConfig $config) => [
                'id' => $config->id,
                'name' => $config->name,
                'server_label' => $config->server_label,
                'server_url' => $config->server_url,
                'connector_id' => $config->connector_id,
                'allowed_tools' => $config->allowed_tools,
                'require_approval' => $config->require_approval,
                'is_enabled' => $config->is_enabled,
                'is_read_only' => $config->is_read_only,
                'has_authorization' => ! empty($config->authorization),
                'has_headers' => ! empty($config->headers),
                'metadata' => $config->metadata,
                'created_at' => $config->created_at,
                'updated_at' => $config->updated_at,
            ]);
    }

    private function toRealtimeTool(McpToolConfig $config): ?array
    {
        if (empty($config->allowed_tools)) {
            return null;
        }

        if ($config->authorization && $this->hasAuthorizationHeader($config->headers ?? [])) {
            return null;
        }

        $tool = [
            'type' => 'mcp',
            'server_label' => $config->server_label,
            'require_approval' => $config->is_read_only
                ? $config->require_approval
                : 'always',
        ];

        if ($config->server_url) {
            $tool['server_url'] = $config->server_url;
        }

        if ($config->connector_id) {
            $tool['connector_id'] = $config->connector_id;
        }

        if ($config->authorization) {
            $tool['authorization'] = $config->authorization;
        }

        if ($config->headers) {
            $tool['headers'] = $config->headers;
        }

        $tool['allowed_tools'] = $config->allowed_tools;

        return $tool;
    }

    private function functionTool(string $name, string $description, array $properties, array $required): array
    {
        foreach ($properties as $propertyName => &$property) {
            if (! in_array($propertyName, $required, true) && is_string($property['type'] ?? null)) {
                $property['type'] = [$property['type'], 'null'];
            }
        }
        unset($property);

        return [
            'type' => 'function',
            'name' => $name,
            'description' => $description,
            'parameters' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => array_keys($properties),
                'additionalProperties' => false,
            ],
        ];
    }

    private function hasAuthorizationHeader(array $headers): bool
    {
        return collect(array_keys($headers))
            ->contains(fn (string $header): bool => strtolower($header) === 'authorization');
    }
}
