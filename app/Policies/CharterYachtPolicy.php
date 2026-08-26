<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CharterYacht;
use App\Models\User;

/**
 * Charter-listing authorization — the same permission model as sale
 * listings: brokers manage their own listings only (assigned_broker_id),
 * enforced here and in the index query builder, never just in the UI.
 *
 * // reference-implementation.md §Roles
 */
class CharterYachtPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ManageListings->value)
            || $user->can(Permission::ManageOwnListings->value);
    }

    public function view(User $user, CharterYacht $yacht): bool
    {
        return $user->can(Permission::ManageListings->value)
            || ($user->can(Permission::ManageOwnListings->value) && $yacht->assigned_broker_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, CharterYacht $yacht): bool
    {
        return $this->view($user, $yacht);
    }
}
