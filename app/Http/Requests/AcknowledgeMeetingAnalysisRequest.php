<?php

namespace App\Http\Requests;

use App\Enums\AnalysisDeliveryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AcknowledgeMeetingAnalysisRequest extends FormRequest
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
            'lease_token' => ['required', 'uuid'],
            'status' => [
                'required',
                Rule::in([
                    AnalysisDeliveryStatus::Completed->value,
                    AnalysisDeliveryStatus::Failed->value,
                ]),
            ],
            'error_code' => ['nullable', 'string', 'max:100'],
        ];
    }
}
