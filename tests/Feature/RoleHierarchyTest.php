<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role as RoleModel;

test('the five role hierarchy is seeded', function () {
    $this->seed(RoleSeeder::class);

    expect(RoleModel::pluck('name')->all())
        ->toEqualCanonicalizing(array_column(Role::cases(), 'value'));
});

test('seeding roles twice does not duplicate them', function () {
    $this->seed(RoleSeeder::class);
    $this->seed(RoleSeeder::class);

    expect(RoleModel::count())->toBe(count(Role::cases()));
});

test('a user can be assigned a role from the enum', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin);

    expect($user->hasRole(Role::SuperAdmin))->toBeTrue()
        ->and($user->hasRole(Role::Viewer))->toBeFalse();
});
