<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BulkPersistCategorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'categorizations' => ['required', 'array'],
            'categorizations.*.call_id' => ['required', 'integer', 'exists:calls,id'],
            'categorizations.*.category' => ['required', 'string'],
            'categorizations.*.sub_category' => ['nullable', 'string'],
            'categorizations.*.confidence' => ['required', 'numeric', 'between:0,1'],
        ];
    }
}
