<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RealtimeClientSecretRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purpose' => 'required|in:salesperson_transcription,customer_transcription,copilot',
            'template_id' => 'nullable|exists:templates,id',
            'context' => 'nullable|array',
            'context.customer_name' => 'nullable|string|max:255',
            'context.customer_company' => 'nullable|string|max:255',
            'context.safety_identifier' => 'nullable|string|max:255',
        ];
    }
}
