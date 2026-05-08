<?php

namespace App\Policies;

use App\Models\CompanyPbxAccount;
use App\Models\User;

/**
 * Authorization policy for CompanyPbxAccount resources.
 *
 * Only admins manage PBX account bindings; non-admins may view their own
 * company's bindings (read-only).
 */
class CompanyPbxAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, CompanyPbxAccount $account): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->company_id === $account->company_id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, CompanyPbxAccount $account): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, CompanyPbxAccount $account): bool
    {
        return $user->isAdmin();
    }
}
