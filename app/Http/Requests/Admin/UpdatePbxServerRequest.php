<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePbxServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('pbx_providers', 'name')->ignore($id)],
            // Write-only: blank/absent means "keep the existing key".
            'api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'secret_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'base_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
