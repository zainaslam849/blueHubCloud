<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PersistCategorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'call_id' => ['required', 'integer', 'exists:calls,id'],
            'category' => ['required', 'string'],
            'sub_category' => ['nullable', 'string'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
        ];
    }
}
