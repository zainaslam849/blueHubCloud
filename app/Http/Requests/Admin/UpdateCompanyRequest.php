<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id') ?? $this->route('company');

        return [
            'name' => ['sometimes', 'string', 'max:255', 'unique:companies,name,' . (int) $id],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', 'in:active,inactive'],
            'server_id' => ['sometimes', 'string', 'max:100'],
            'pbx_provider_id' => ['sometimes', 'integer', 'exists:pbx_providers,id'],
            'tenant_code' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
