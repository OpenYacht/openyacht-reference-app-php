<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * User management authorization.
 *
 * Rules that must survive any refactor: only super_admins assign
 * super_admin; no self-escalation; at least one super_admin always exists.
 * The role-value-specific rules live in UpdateUserRoleRequest, where the
 * requested role is known.
 *
 * // reference-implementation.md §Roles
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ManageUsers->value);
    }

    /**
     * Accounts are minted by an administrator — self-registration is
     * disabled, so this is the only way in besides openyacht:create-user.
     */
    public function create(User $user): bool
    {
        return $user->can(Permission::ManageUsers->value);
    }

    /**
     * A user's role can be changed by anyone holding the users.manage
     * permission — never their own (no self-escalation, and no
     * self-demotion out of the last super_admin either).
     */
    public function updateRole(User $user, User $target): bool
    {
        return $user->can(Permission::ManageUsers->value)
            && ! $user->is($target);
    }
}
