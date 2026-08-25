<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:companies,name'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', 'in:active,inactive'],
            'server_id' => ['sometimes', 'string', 'max:100'],
            'pbx_provider_id' => ['sometimes', 'integer', 'exists:pbx_providers,id'],
            'tenant_code' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
