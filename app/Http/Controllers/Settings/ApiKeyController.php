<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\ApiKeyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    private ApiKeyService $apiKeyService;

    public function __construct(ApiKeyService $apiKeyService)
    {
        $this->apiKeyService = $apiKeyService;
    }

    /**
     * Show the API keys settings page
     */
    public function edit(Request $request): Response
    {
        $hasApiKey = $this->apiKeyService->hasApiKey();
        $isUsingEnvKey = ! cache()->has('app_openai_api_key') && $hasApiKey;

        return Inertia::render('settings/ApiKeys', [
            'hasApiKey' => $hasApiKey,
            'isUsingEnvKey' => $isUsingEnvKey,
        ]);
    }

    /**
     * Update the OpenAI API key
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'openai_api_key' => ['required', 'string', 'min:20'],
        ]);

        $apiKey = $request->input('openai_api_key');

        // Validate the API key
        if (! $this->apiKeyService->validateApiKey($apiKey)) {
            throw ValidationException::withMessages([
                'openai_api_key' => ['The provided API key is invalid. Please check and try again.'],
            ]);
        }

        // Store the API key
        $this->apiKeyService->setApiKey($apiKey);

        return redirect()->route('api-keys.edit')->with('success', 'API key updated successfully.');
    }

    /**
     * Delete the OpenAI API key
     */
    public function destroy(Request $request): RedirectResponse
    {
        $this->apiKeyService->removeApiKey();

        return redirect()->route('api-keys.edit')->with('success', 'API key deleted successfully.');
    }

    /**
     * Store the OpenAI API key (used by onboarding)
     */
    public function store(Request $request)
    {
        $request->validate([
            'api_key' => ['required', 'string', 'min:20'],
        ]);

        $apiKey = $request->input('api_key');

        // Validate the API key
        if (! $this->apiKeyService->validateApiKey($apiKey)) {
            return response()->json([
                'success' => false,
                'message' => 'The provided API key is invalid. Please check and try again.',
            ], 422);
        }

        // Store the API key
        $this->apiKeyService->setApiKey($apiKey);

        return response()->json([
            'success' => true,
            'message' => 'API key saved successfully.',
        ]);
    }
}
