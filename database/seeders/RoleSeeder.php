<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Seed the five-role hierarchy and its default permission matrix.
     * Idempotent: re-running creates nothing twice and only assigns the
     * defaults to roles that have no permissions yet, so an installation's
     * tuned matrix survives re-seeding. The one exception is super_admin,
     * whose "always holds every permission" invariant is enforced on every
     * run — the way permissions added after install reach existing
     * installations (the role is not editable in the matrix, so there is
     * no tuning to preserve).
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value);
        }

        foreach (RoleEnum::cases() as $roleEnum) {
            $role = Role::findOrCreate($roleEnum->value);

            if ($roleEnum === RoleEnum::SuperAdmin) {
                $role->syncPermissions(
                    array_column(PermissionEnum::cases(), 'value'),
                );

                continue;
            }

            if ($role->permissions()->count() === 0) {
                $role->syncPermissions(
                    array_column(PermissionEnum::defaultsForRole($roleEnum), 'value'),
                );
            }
        }
    }
}
