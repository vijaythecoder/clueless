<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AnalyzeMeetingAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lease_token' => ['required', 'uuid'],
        ];
    }
}
