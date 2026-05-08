<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * Authorization policy for Company resources.
 *
 * Only admins manage companies; non-admin users may only view their own.
 */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Company $company): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->company_id === $company->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Company $company): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Company $company): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, Company $company): bool
    {
        return $user->isAdmin();
    }
}
