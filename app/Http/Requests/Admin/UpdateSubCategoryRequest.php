<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $categoryId = $this->route('categoryId') ?? $this->route('category');
        $subCategoryId = $this->route('subCategoryId') ?? $this->route('subCategory');

        return [
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('sub_categories', 'name')
                    ->where('category_id', $categoryId)
                    ->ignore($subCategoryId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_enabled' => ['boolean'],
        ];
    }
}
