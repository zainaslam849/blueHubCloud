<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'site_name' => ['nullable', 'string', 'max:120'],
            'admin_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'admin_favicon' => ['nullable', 'file', 'mimes:png,ico,svg', 'max:1024'],
            'admin_logo_clear' => ['nullable', 'boolean'],
            'admin_favicon_clear' => ['nullable', 'boolean'],
        ];
    }
}
