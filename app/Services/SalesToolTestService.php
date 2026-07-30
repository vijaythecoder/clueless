<?php

namespace App\Services;

use App\Exceptions\UnsafeSalesToolConfigurationException;
use App\Models\McpToolConfig;

class SalesToolTestService
{
    public function __construct(
        private SalesToolRegistry $salesToolRegistry,
        private OpenAIRealtimeService $openAIRealtimeService,
    ) {}

    public function createClientSecret(McpToolConfig $config): array
    {
        $tool = $this->salesToolRegistry->realtimeTool($config);

        if (! $tool) {
            throw new UnsafeSalesToolConfigurationException;
        }

        return $this->openAIRealtimeService->createMcpToolTestClientSecret($tool);
    }
}
