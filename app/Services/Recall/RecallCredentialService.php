<?php

namespace App\Services\Recall;

use App\Models\SecureSetting;
use DomainException;

final class RecallCredentialService
{
    public function apiKey(): string
    {
        return $this->requiredCredential(
            SecureSetting::RECALL_API_KEY,
            'services.recall.api_key',
            'Recall API key is not configured',
        );
    }

    public function webhookSecret(): string
    {
        return $this->requiredCredential(
            SecureSetting::RECALL_WEBHOOK_SECRET,
            'services.recall.webhook_secret',
            'Recall webhook secret is not configured',
        );
    }

    public function isConfigured(): bool
    {
        return $this->hasApiKey() && $this->hasWebhookSecret();
    }

    /**
     * @return array{configured: bool, region: string, webhook_url: string|null}
     */
    public function status(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'region' => config('services.recall.region'),
            'webhook_url' => config('services.recall.webhook_url'),
        ];
    }

    public function hasApiKey(): bool
    {
        return $this->credential(SecureSetting::RECALL_API_KEY, 'services.recall.api_key') !== null;
    }

    public function hasWebhookSecret(): bool
    {
        return $this->credential(SecureSetting::RECALL_WEBHOOK_SECRET, 'services.recall.webhook_secret') !== null;
    }

    public function setApiKey(string $apiKey): void
    {
        $this->store(SecureSetting::RECALL_API_KEY, $apiKey);
    }

    public function setWebhookSecret(string $webhookSecret): void
    {
        $this->store(SecureSetting::RECALL_WEBHOOK_SECRET, $webhookSecret);
    }

    private function requiredCredential(string $settingKey, string $configKey, string $message): string
    {
        return $this->credential($settingKey, $configKey) ?? throw new DomainException($message);
    }

    private function credential(string $settingKey, string $configKey): ?string
    {
        $storedValue = SecureSetting::query()->where('key', $settingKey)->value('value');

        if (is_string($storedValue) && $storedValue !== '') {
            return $storedValue;
        }

        $environmentValue = config($configKey);

        return is_string($environmentValue) && $environmentValue !== '' ? $environmentValue : null;
    }

    private function store(string $key, string $value): void
    {
        SecureSetting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
