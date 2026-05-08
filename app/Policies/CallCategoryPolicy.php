<?php

namespace App\Policies;

use App\Models\CallCategory;
use App\Models\User;

/**
 * Authorization policy for CallCategory resources.
 *
 * Admins manage all categories. Non-admin users may only view categories
 * belonging to their own company.
 */
class CallCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CallCategory $category): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->company_id === $category->company_id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, CallCategory $category): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, CallCategory $category): bool
    {
        return $user->isAdmin();
    }

    public function toggle(User $user, CallCategory $category): bool
    {
        return $user->isAdmin();
    }
}
