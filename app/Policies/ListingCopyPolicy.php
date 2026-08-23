<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Synced partner listings are shared business data, not public content:
 * copies are never re-served publicly, and browsing them in the app
 * requires a listings permission — a user with no role sees nothing.
 *
 * // reference-implementation.md §Roles
 */
class ListingCopyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ManageListings->value)
            || $user->can(Permission::ManageOwnListings->value);
    }
}
