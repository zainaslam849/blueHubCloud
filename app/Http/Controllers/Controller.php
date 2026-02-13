<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Support\Facades\Auth;

abstract class Controller
{
    protected function resolveAuthenticatedCompany(): Company
    {
        $adminUser = Auth::guard('admin')->user();
        $webUser = Auth::user();
        $user = $adminUser ?? $webUser;

        if (! $user || ! $user->company_id) {
            abort(403, 'Company context is required.');
        }

        $company = Company::find($user->company_id);

        if (! $company) {
            abort(403, 'Company not found.');
        }

        return $company;
    }
}
