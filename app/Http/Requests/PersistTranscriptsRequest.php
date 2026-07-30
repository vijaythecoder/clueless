<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PersistTranscriptsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->has('transcripts')) {
            return [
                'transcripts' => ['required', 'array', 'min:1', 'max:500'],
                ...$this->transcriptRules('transcripts.*.'),
            ];
        }

        return $this->transcriptRules();
    }

    public function transcripts(): array
    {
        $validated = $this->validated();

        return $validated['transcripts'] ?? [$validated];
    }

    private function transcriptRules(string $prefix = ''): array
    {
        return [
            $prefix.'speaker' => ['required', 'in:salesperson,customer,system'],
            $prefix.'source_stream' => ['nullable', 'string', 'max:255'],
            $prefix.'text' => ['required', 'string', 'max:50000'],
            $prefix.'spoken_at' => ['required', 'integer', 'min:0'],
            $prefix.'openai_item_id' => ['nullable', 'string', 'max:255'],
            $prefix.'status' => ['nullable', 'in:partial,final'],
            $prefix.'group_id' => ['nullable', 'string', 'max:255'],
            $prefix.'system_category' => ['nullable', 'string', 'max:255'],
            $prefix.'metadata' => ['nullable', 'array'],
            $prefix.'provider' => ['prohibited'],
            $prefix.'provider_item_id' => ['prohibited'],
            $prefix.'meeting_capture_session_id' => ['prohibited'],
            $prefix.'meeting_participant_id' => ['prohibited'],
            $prefix.'started_offset_ms' => ['prohibited'],
            $prefix.'ended_offset_ms' => ['prohibited'],
            $prefix.'order_index' => ['prohibited'],
        ];
    }
}
