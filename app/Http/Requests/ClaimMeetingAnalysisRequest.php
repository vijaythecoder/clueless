<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ClaimMeetingAnalysisRequest extends FormRequest
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
            'through_cursor' => ['required', 'integer', 'min:0'],
        ];
    }
}
