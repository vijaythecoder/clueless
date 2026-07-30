<?php

namespace App\Http\Requests;

use App\Enums\SalesRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssignMeetingParticipantRequest extends FormRequest
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
            'sales_role' => ['required', Rule::in([SalesRole::Salesperson->value])],
        ];
    }
}
