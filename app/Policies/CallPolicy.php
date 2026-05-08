<?php

namespace App\Policies;

use App\Models\Call;
use App\Models\User;

/**
 * Authorization policy for Call resources.
 *
 * Admins can view/modify all calls. Non-admin users are scoped to calls
 * belonging to their own company.
 */
class CallPolicy
{
    public function viewAny(User $user): bool
    {
        // All authenticated users may list calls; controllers must scope
        // the query by company_id for non-admins.
        return true;
    }

    public function view(User $user, Call $call): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->company_id === $call->company_id;
    }

    public function update(User $user, Call $call): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Call $call): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Call $call): bool
    {
        return $user->isAdmin();
    }

    public function categorize(User $user, Call $call): bool
    {
        return $user->isAdmin();
    }
}
