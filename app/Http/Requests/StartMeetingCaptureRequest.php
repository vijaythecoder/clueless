<?php

namespace App\Http\Requests;

use App\Enums\MeetingProvider;
use App\Rules\TeamsMeetingUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StartMeetingCaptureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::enum(MeetingProvider::class)],
            'meeting_url' => [
                'required_if:provider,'.MeetingProvider::Recall->value,
                'nullable',
                'string',
                'max:2048',
                new TeamsMeetingUrl,
            ],
            'idempotency_key' => ['required', 'uuid'],
            'template_used' => ['nullable', 'string'],
            'customer_name' => ['nullable', 'string'],
            'customer_company' => ['nullable', 'string'],
        ];
    }
}
