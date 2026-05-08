<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SyncTenantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pbx_provider_id' => ['required', 'integer', 'exists:pbx_providers,id'],
        ];
    }
}
