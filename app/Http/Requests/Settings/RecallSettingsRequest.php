<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class RecallSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'api_key' => ['nullable', 'string', 'min:20'],
            'webhook_secret' => ['nullable', 'string', 'starts_with:whsec_'],
        ];
    }
}
