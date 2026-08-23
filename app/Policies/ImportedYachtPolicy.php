<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ImportedYacht;
use App\Models\User;

/**
 * Imported partner listings follow the same visibility rule as the
 * synced copies they come from: a listings permission is required to
 * view them in the app.
 *
 * // reference-implementation.md §Roles
 */
class ImportedYachtPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ManageListings->value)
            || $user->can(Permission::ManageOwnListings->value);
    }

    public function view(User $user, ImportedYacht $importedYacht): bool
    {
        return $this->viewAny($user);
    }
}
