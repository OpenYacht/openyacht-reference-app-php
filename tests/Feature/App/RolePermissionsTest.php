<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role as RoleModel;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function actorWithRole(Role $role): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
}

test('the default matrix follows the role scope table', function () {
    $permissionsOf = fn (Role $role) => RoleModel::findByName($role->value)
        ->permissions->pluck('name')->sort()->values()->all();

    expect($permissionsOf(Role::SuperAdmin))
        ->toEqualCanonicalizing(array_column(Permission::cases(), 'value'))
        ->and($permissionsOf(Role::Admin))
        ->toEqualCanonicalizing([
            Permission::ManageUsers->value,
            Permission::ManageListings->value,
            Permission::ManageMedia->value,
            Permission::ManageContacts->value,
            Permission::ManageSettings->value,
            Permission::ViewActivityLog->value,
        ])
        ->and($permissionsOf(Role::Broker))
        ->toEqualCanonicalizing([
            Permission::ManageOwnListings->value,
            Permission::ManageOwnContacts->value,
        ])
        ->and($permissionsOf(Role::Viewer))->toBe([]);
});

test('re-seeding restores every permission to super_admin but preserves tuned roles', function () {
    RoleModel::findByName(Role::SuperAdmin->value)->syncPermissions([Permission::ManageUsers->value]);
    RoleModel::findByName(Role::Editor->value)->syncPermissions([Permission::ManageMedia->value]);

    $this->seed(RoleSeeder::class);

    // The invariant that super_admin always holds every permission is how
    // permissions added after install reach existing installations; the
    // other roles' tuned matrix survives.
    expect(RoleModel::findByName(Role::SuperAdmin->value)->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(array_column(Permission::cases(), 'value'))
        ->and(RoleModel::findByName(Role::Editor->value)->permissions->pluck('name')->all())
        ->toBe([Permission::ManageMedia->value]);
});

test('admins can view the roles page but other roles cannot', function () {
    $this->actingAs(actorWithRole(Role::Admin))
        ->get(route('roles.index'))
        ->assertOk();

    $this->actingAs(actorWithRole(Role::Editor))
        ->get(route('roles.index'))
        ->assertForbidden();
});

test('a super admin can change a role\'s permissions', function () {
    $superAdmin = actorWithRole(Role::SuperAdmin);

    $this->actingAs($superAdmin)
        ->put(route('roles.permissions.update', Role::Editor->value), [
            'permissions' => [Permission::ManageListings->value],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(RoleModel::findByName(Role::Editor->value)->permissions->pluck('name')->all())
        ->toBe([Permission::ManageListings->value]);
});

test('an admin without roles.manage cannot change permissions', function () {
    $this->actingAs(actorWithRole(Role::Admin))
        ->put(route('roles.permissions.update', Role::Editor->value), [
            'permissions' => [],
        ])
        ->assertForbidden();
});

test('the super admin role is never editable', function () {
    $superAdmin = actorWithRole(Role::SuperAdmin);

    $this->actingAs($superAdmin)
        ->put(route('roles.permissions.update', Role::SuperAdmin->value), [
            'permissions' => [],
        ])
        ->assertSessionHasErrors('permissions');

    expect(RoleModel::findByName(Role::SuperAdmin->value)->permissions->count())
        ->toBe(count(Permission::cases()));
});

test('permission changes are recorded in the activity log', function () {
    $superAdmin = actorWithRole(Role::SuperAdmin);

    $this->actingAs($superAdmin)
        ->put(route('roles.permissions.update', Role::Viewer->value), [
            'permissions' => [Permission::ManageContacts->value],
        ]);

    $entry = Activity::query()->where('event', 'permissions_changed')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($superAdmin->id)
        ->and($entry->properties['role'])->toBe(Role::Viewer->value)
        ->and($entry->properties['to'])->toBe([Permission::ManageContacts->value]);
});

test('granting roles.manage lets an admin edit the matrix', function () {
    RoleModel::findByName(Role::Admin->value)
        ->givePermissionTo(Permission::ManageRoles->value);

    $this->actingAs(actorWithRole(Role::Admin))
        ->put(route('roles.permissions.update', Role::Viewer->value), [
            'permissions' => [],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});
