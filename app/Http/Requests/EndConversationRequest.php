<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EndConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'duration_seconds' => ['required', 'integer', 'min:0'],
            'final_intent' => ['nullable', 'string', 'max:255'],
            'final_buying_stage' => ['nullable', 'string', 'max:255'],
            'final_engagement_level' => ['nullable', 'integer', 'between:0,100'],
            'final_sentiment' => ['nullable', 'string', 'max:255'],
            'ai_summary' => ['nullable', 'string'],
        ];
    }
}
