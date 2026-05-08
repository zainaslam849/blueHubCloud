<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OverrideCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'call_id' => ['required', 'integer', 'exists:calls,id'],
            'category_id' => ['nullable', 'integer', 'exists:call_categories,id'],
            'sub_category_id' => ['nullable', 'integer', 'exists:sub_categories,id'],
            'sub_category_label' => ['nullable', 'string', 'max:255'],
        ];
    }
}
