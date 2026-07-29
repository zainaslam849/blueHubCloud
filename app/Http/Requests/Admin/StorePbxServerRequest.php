<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StorePbxServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:pbx_providers,name'],
            // Either paste an API key (the app writes the secret to AWS) or
            // reference an existing Secrets Manager secret by name.
            'api_key' => ['required_without:secret_name', 'nullable', 'string', 'max:500'],
            'secret_name' => ['required_without:api_key', 'nullable', 'string', 'max:255'],
            'base_url' => ['required_with:api_key', 'nullable', 'url', 'max:255'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }

    public function messages(): array
    {
        return [
            'api_key.required_without' => 'Provide either an API key or an existing AWS secret name.',
            'secret_name.required_without' => 'Provide either an API key or an existing AWS secret name.',
            'base_url.required_with' => 'The server hostname (base URL) is required when adding an API key.',
        ];
    }
}
