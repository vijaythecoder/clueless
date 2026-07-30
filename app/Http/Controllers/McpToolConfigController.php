<?php

namespace App\Http\Controllers;

use App\Exceptions\MissingOpenAIKeyException;
use App\Exceptions\RealtimeClientSecretException;
use App\Exceptions\UnsafeSalesToolConfigurationException;
use App\Models\McpToolConfig;
use App\Services\SalesToolRegistry;
use App\Services\SalesToolTestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class McpToolConfigController extends Controller
{
    public function __construct(
        private SalesToolRegistry $salesToolRegistry,
        private SalesToolTestService $salesToolTestService,
    ) {}

    public function edit()
    {
        return Inertia::render('settings/SalesTools', [
            'tools' => $this->salesToolRegistry->publicConfigs(),
        ]);
    }

    public function index()
    {
        return response()->json([
            'tools' => $this->salesToolRegistry->publicConfigs(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->salesToolRegistry->validateConfig($request->all());

        $tool = McpToolConfig::create($validated);

        return response()->json([
            'tool' => $this->salesToolRegistry->publicConfigs()->firstWhere('id', $tool->id),
        ], 201);
    }

    public function update(Request $request, McpToolConfig $mcpToolConfig)
    {
        $data = $request->all();

        if (! array_key_exists('authorization', $data) && $mcpToolConfig->authorization) {
            $data['authorization'] = $mcpToolConfig->authorization;
        }

        if (! array_key_exists('headers', $data) && $mcpToolConfig->headers) {
            $data['headers'] = $mcpToolConfig->headers;
        }

        $validated = $this->salesToolRegistry->validateConfig($data, $mcpToolConfig);
        $mcpToolConfig->update($validated);

        return response()->json([
            'tool' => $this->salesToolRegistry->publicConfigs()->firstWhere('id', $mcpToolConfig->id),
        ]);
    }

    public function test(McpToolConfig $mcpToolConfig)
    {
        try {
            return response()->json(
                $this->salesToolTestService->createClientSecret($mcpToolConfig),
            );
        } catch (UnsafeSalesToolConfigurationException) {
            return response()->json([
                'success' => false,
                'message' => 'This sales tool configuration is not safe to test.',
            ], 422);
        } catch (MissingOpenAIKeyException) {
            return response()->json([
                'success' => false,
                'message' => 'Configure an OpenAI API key before testing sales tools.',
            ], 422);
        } catch (RealtimeClientSecretException $exception) {
            Log::warning('Sales tool validation failed', [
                'config_id' => $mcpToolConfig->id,
                'status' => $exception->statusCode(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to prepare this sales tool test.',
            ], 502);
        } catch (\Throwable $exception) {
            Log::warning('Unexpected sales tool validation failure', [
                'config_id' => $mcpToolConfig->id,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to prepare this sales tool test.',
            ], 502);
        }
    }

    public function destroy(McpToolConfig $mcpToolConfig)
    {
        $mcpToolConfig->delete();

        return response()->json(['success' => true]);
    }
}
