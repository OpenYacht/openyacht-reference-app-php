<?php

namespace App\Enums;

/**
 * Granular permissions, seeded to the five-role hierarchy.
 *
 * Policies check permissions rather than roles, so an installation can tune
 * what a role may do without touching code. The five roles themselves are
 * fixed; the super_admin role always holds every permission.
 *
 * // reference-implementation.md §Roles
 */
enum Permission: string
{
    /** View users and assign roles (the role-hierarchy rules still apply). */
    case ManageUsers = 'users.manage';

    /** Edit the role/permission matrix. Super admins only by default. */
    case ManageRoles = 'roles.manage';

    /** Create and edit all listings. */
    case ManageListings = 'listings.manage';

    /** Create and edit own listings only (assigned_broker_id). */
    case ManageOwnListings = 'listings.manage_own';

    /** Upload and manage media. */
    case ManageMedia = 'media.manage';

    /** Manage all contacts. */
    case ManageContacts = 'contacts.manage';

    /** Manage own client contacts only. */
    case ManageOwnContacts = 'contacts.manage_own';

    /** General application settings. */
    case ManageSettings = 'settings.manage';

    /** Federation configuration: partners, keys, sharing rules. */
    case ManageFederation = 'federation.manage';

    /**
     * The translated display label for this permission.
     */
    public function label(): string
    {
        return __('permissions.'.$this->value);
    }

    /**
     * The default permission set of each role, per the scope table.
     *
     * @return list<self>
     */
    public static function defaultsForRole(Role $role): array
    {
        return match ($role) {
            Role::SuperAdmin => self::cases(),
            Role::Admin => [
                self::ManageUsers,
                self::ManageListings,
                self::ManageMedia,
                self::ManageContacts,
                self::ManageSettings,
            ],
            Role::Editor => [
                self::ManageListings,
                self::ManageMedia,
                self::ManageContacts,
            ],
            Role::Broker => [
                self::ManageOwnListings,
                self::ManageOwnContacts,
            ],
            Role::Viewer => [],
        };
    }
}
