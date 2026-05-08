<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates payload for building a per-call categorization prompt preview.
 */
class BuildCallPromptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'transcript' => ['required', 'string'],
            'direction' => ['nullable', 'in:inbound,outbound'],
            'status' => ['nullable', 'in:completed,missed,failed'],
            'duration' => ['nullable', 'integer', 'min:0'],
            'is_after_hours' => ['nullable', 'boolean'],
        ];
    }
}
