<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RecallSettingsRequest;
use App\Services\Recall\RecallCredentialService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RecallSettingsController extends Controller
{
    public function __construct(private RecallCredentialService $credentials) {}

    public function edit(): Response
    {
        return Inertia::render('settings/Recall', [
            'has_api_key' => $this->credentials->hasApiKey(),
            'has_webhook_secret' => $this->credentials->hasWebhookSecret(),
            ...$this->credentials->status(),
        ]);
    }

    public function update(RecallSettingsRequest $request): RedirectResponse
    {
        $credentials = $request->validated();

        if (filled($credentials['api_key'] ?? null)) {
            $this->credentials->setApiKey($credentials['api_key']);
        }

        if (filled($credentials['webhook_secret'] ?? null)) {
            $this->credentials->setWebhookSecret($credentials['webhook_secret']);
        }

        return redirect()->route('recall.edit')->with('success', 'Recall credentials updated successfully.');
    }
}
