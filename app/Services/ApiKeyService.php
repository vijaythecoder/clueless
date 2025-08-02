<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ApiKeyService
{
    private const CACHE_KEY = 'app_openai_api_key';

    /**
     * Get the OpenAI API key from cache or environment
     */
    public function getApiKey(): ?string
    {
        // Check cache first (stored via settings page)
        $cachedKey = Cache::get(self::CACHE_KEY);
        if ($cachedKey) {
            return $cachedKey;
        }

        // Fall back to environment variable
        return config('openai.api_key');
    }

    /**
     * Store the API key in cache
     */
    public function setApiKey(string $apiKey): void
    {
        Cache::forever(self::CACHE_KEY, $apiKey);
    }

    /**
     * Remove the stored API key
     */
    public function removeApiKey(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Check if an API key is available
     */
    public function hasApiKey(): bool
    {
        return ! empty($this->getApiKey());
    }

    /**
     * Validate an API key with OpenAI
     */
    public function validateApiKey(string $apiKey): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
            ])->get('https://api.openai.com/v1/models');

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
