<?php

namespace App\Enums;

/**
 * The five-role hierarchy.
 *
 * Rules that must survive any refactor: only super_admins assign super_admin;
 * no self-escalation; at least one super_admin always exists; role changes are
 * activity-logged; broker query scope is enforced in policies and query
 * builders, not in the UI.
 *
 * // reference-implementation.md §Roles
 */
enum Role: string
{
    /** Everything, including federation configuration and keys. */
    case SuperAdmin = 'super_admin';

    /** Yacht and user management, general settings; no federation config. */
    case Admin = 'admin';

    /** All listings, media, contacts. */
    case Editor = 'editor';

    /** Own listings only (assigned_broker_id), own client contacts. */
    case Broker = 'broker';

    /** Read-only. */
    case Viewer = 'viewer';

    /**
     * The translated display label for this role.
     */
    public function label(): string
    {
        return __('roles.'.$this->value);
    }
}
