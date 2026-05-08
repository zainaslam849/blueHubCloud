<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $category = $this->route('category');
        $companyId = is_object($category) ? $category->company_id : null;
        $categoryId = is_object($category) ? $category->id : ($category ?: null);

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('call_categories', 'name')
                    ->where('company_id', $companyId)
                    ->ignore($categoryId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_enabled' => ['boolean'],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
        ];
    }
}
