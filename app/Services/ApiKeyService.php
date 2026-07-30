<?php

namespace App\Services;

use App\Models\SecureSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ApiKeyService
{
    private const LEGACY_CACHE_KEY = 'app_openai_api_key';

    public function getApiKey(): ?string
    {
        $storedKey = SecureSetting::query()
            ->where('key', SecureSetting::OPENAI_API_KEY)
            ->value('value');

        if ($storedKey) {
            return $storedKey;
        }

        $legacyKey = Cache::get(self::LEGACY_CACHE_KEY);

        if (is_string($legacyKey) && $legacyKey !== '') {
            $this->persistApiKey($legacyKey);

            $migratedKey = SecureSetting::query()
                ->where('key', SecureSetting::OPENAI_API_KEY)
                ->value('value');

            if ($migratedKey === $legacyKey) {
                Cache::forget(self::LEGACY_CACHE_KEY);

                return $migratedKey;
            }
        }

        return config('openai.api_key');
    }

    public function setApiKey(string $apiKey): void
    {
        $this->persistApiKey($apiKey);
        Cache::forget(self::LEGACY_CACHE_KEY);
    }

    private function persistApiKey(string $apiKey): void
    {
        SecureSetting::updateOrCreate(
            ['key' => SecureSetting::OPENAI_API_KEY],
            ['value' => $apiKey],
        );
    }

    public function removeApiKey(): void
    {
        SecureSetting::query()
            ->where('key', SecureSetting::OPENAI_API_KEY)
            ->delete();
        Cache::forget(self::LEGACY_CACHE_KEY);
    }

    public function hasApiKey(): bool
    {
        return ! empty($this->getApiKey());
    }

    public function hasStoredApiKey(): bool
    {
        return SecureSetting::query()
            ->where('key', SecureSetting::OPENAI_API_KEY)
            ->exists();
    }

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
