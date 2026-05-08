<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the destructive enforce-threshold action.
 *
 * `dry_run` and `confirm` defaults remain false to ensure a caller must
 * explicitly opt in to writes. The controller still requires confirm=true
 * when dry_run is false.
 */
class EnforceThresholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'threshold' => ['nullable', 'numeric', 'between:0.5,1.0'],
            'dry_run' => ['nullable', 'boolean'],
            'confirm' => ['nullable', 'boolean'],
        ];
    }
}
