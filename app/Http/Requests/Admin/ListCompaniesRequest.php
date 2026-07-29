<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates query parameters for listing companies.
 *
 * Authorization is enforced by the route's auth:admin middleware and a
 * controller-side $this->authorize('viewAny', Company::class) check, so this
 * request only validates input shape.
 */
class ListCompaniesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'search' => ['sometimes', 'string', 'max:255'],
            'sort' => ['sometimes', 'in:name,status,timezone,created_at'],
            'direction' => ['sometimes', 'in:asc,desc'],
            'status' => ['sometimes', 'in:active,inactive'],
            'pbx_provider_id' => ['sometimes', 'integer', 'exists:pbx_providers,id'],
            'include_deleted' => ['sometimes', 'in:true,false,1,0'],
        ];
    }
}
