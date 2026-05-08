<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization policy for AI settings (admin-only configuration).
 *
 * AI settings are global system configuration; only admins may view or
 * modify them. This policy is class-level (no model binding).
 */
class AiSettingsPolicy
{
    public function view(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user): bool
    {
        return $user->isAdmin();
    }

    public function manage(User $user): bool
    {
        return $user->isAdmin();
    }
}
