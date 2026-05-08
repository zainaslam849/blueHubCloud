<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BulkOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'overrides' => ['required', 'array'],
            'overrides.*.call_id' => ['required', 'integer', 'exists:calls,id'],
            'overrides.*.category_id' => ['nullable', 'integer', 'exists:call_categories,id'],
            'overrides.*.sub_category_id' => ['nullable', 'integer', 'exists:sub_categories,id'],
        ];
    }
}
