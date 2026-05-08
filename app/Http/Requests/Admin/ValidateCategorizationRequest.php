<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ValidateCategorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'category' => ['required', 'string'],
            'sub_category' => ['nullable', 'string'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
        ];
    }
}
