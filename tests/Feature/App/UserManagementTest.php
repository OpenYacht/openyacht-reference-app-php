<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function userWithRole(Role $role): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
}

test('admins and super admins can view user management', function (Role $role) {
    $this->actingAs(userWithRole($role))
        ->get(route('users.index'))
        ->assertOk();
})->with([Role::Admin, Role::SuperAdmin]);

test('other roles cannot view user management', function (Role $role) {
    $this->actingAs(userWithRole($role))
        ->get(route('users.index'))
        ->assertForbidden();
})->with([Role::Editor, Role::Broker, Role::Viewer]);

test('an admin can change a role below super admin', function () {
    $admin = userWithRole(Role::Admin);
    $target = userWithRole(Role::Viewer);

    $this->actingAs($admin)
        ->put(route('users.role.update', $target), ['role' => Role::Broker->value])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($target->fresh()->hasRole(Role::Broker))->toBeTrue();
});

test('only super admins may assign the super admin role', function () {
    $admin = userWithRole(Role::Admin);
    $target = userWithRole(Role::Editor);

    $this->actingAs($admin)
        ->put(route('users.role.update', $target), ['role' => Role::SuperAdmin->value])
        ->assertSessionHasErrors('role');

    expect($target->fresh()->hasRole(Role::SuperAdmin))->toBeFalse();
});

test('only super admins may remove the super admin role', function () {
    userWithRole(Role::SuperAdmin);
    $admin = userWithRole(Role::Admin);
    $target = userWithRole(Role::SuperAdmin);

    $this->actingAs($admin)
        ->put(route('users.role.update', $target), ['role' => Role::Viewer->value])
        ->assertSessionHasErrors('role');

    expect($target->fresh()->hasRole(Role::SuperAdmin))->toBeTrue();
});

test('a super admin can promote and demote across the hierarchy', function () {
    userWithRole(Role::SuperAdmin);
    $superAdmin = userWithRole(Role::SuperAdmin);
    $target = userWithRole(Role::Viewer);

    $this->actingAs($superAdmin)
        ->put(route('users.role.update', $target), ['role' => Role::SuperAdmin->value])
        ->assertSessionHasNoErrors();

    expect($target->fresh()->hasRole(Role::SuperAdmin))->toBeTrue();
});

test('no one can change their own role', function () {
    $superAdmin = userWithRole(Role::SuperAdmin);

    $this->actingAs($superAdmin)
        ->put(route('users.role.update', $superAdmin), ['role' => Role::Viewer->value])
        ->assertForbidden();

    expect($superAdmin->fresh()->hasRole(Role::SuperAdmin))->toBeTrue();
});

test('the last super admin can never be demoted', function () {
    $superAdmin = userWithRole(Role::SuperAdmin);
    $otherSuperAdmin = userWithRole(Role::SuperAdmin);

    $this->actingAs($superAdmin)
        ->put(route('users.role.update', $otherSuperAdmin), ['role' => Role::Admin->value])
        ->assertSessionHasNoErrors();

    $this->actingAs($otherSuperAdmin->fresh())
        ->put(route('users.role.update', $superAdmin), ['role' => Role::Admin->value])
        ->assertSessionHasErrors('role');

    expect($superAdmin->fresh()->hasRole(Role::SuperAdmin))->toBeTrue();
})->group('last-super-admin');

test('role changes are recorded in the activity log', function () {
    $superAdmin = userWithRole(Role::SuperAdmin);
    $target = userWithRole(Role::Viewer);

    $this->actingAs($superAdmin)
        ->put(route('users.role.update', $target), ['role' => Role::Editor->value]);

    $entry = Activity::query()->where('event', 'role_changed')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($superAdmin->id)
        ->and($entry->subject_id)->toBe($target->id)
        ->and($entry->properties['from'])->toBe(Role::Viewer->value)
        ->and($entry->properties['to'])->toBe(Role::Editor->value);
});
