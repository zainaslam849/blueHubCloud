<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CallsNeedingReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'threshold' => ['nullable', 'numeric', 'between:0.5,1.0'],
            'limit' => ['nullable', 'integer', 'between:1,1000'],
        ];
    }
}
