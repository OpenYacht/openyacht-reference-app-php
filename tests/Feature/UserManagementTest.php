<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function actingAsRole(Role $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('an administrator can create a user from the admin', function () {
    $admin = actingAsRole(Role::SuperAdmin);

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'name' => 'Alex Marlow',
            'email' => 'alex@example.com',
            'password' => 'correct-horse-battery-staple',
            'role' => Role::Broker->value,
        ])
        ->assertRedirect();

    $created = User::where('email', 'alex@example.com')->firstOrFail();

    expect($created->name)->toBe('Alex Marlow')
        ->and($created->hasRole(Role::Broker))->toBeTrue()
        ->and(Hash::check('correct-horse-battery-staple', $created->password))->toBeTrue();
});

test('a created user is marked verified because an administrator vouched for the address', function () {
    $admin = actingAsRole(Role::SuperAdmin);

    $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Alex Marlow',
        'email' => 'alex@example.com',
        'password' => 'correct-horse-battery-staple',
        'role' => Role::Viewer->value,
    ]);

    expect(User::where('email', 'alex@example.com')->firstOrFail()->hasVerifiedEmail())->toBeTrue();
});

test('a user without the users permission cannot create users', function () {
    $broker = actingAsRole(Role::Broker);

    $this->actingAs($broker)
        ->post(route('users.store'), [
            'name' => 'Alex Marlow',
            'email' => 'alex@example.com',
            'password' => 'correct-horse-battery-staple',
            'role' => Role::Viewer->value,
        ])
        ->assertForbidden();

    expect(User::where('email', 'alex@example.com')->exists())->toBeFalse();
});

test('only a super admin may mint another super admin', function () {
    $admin = actingAsRole(Role::Admin);

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'name' => 'Alex Marlow',
            'email' => 'alex@example.com',
            'password' => 'correct-horse-battery-staple',
            'role' => Role::SuperAdmin->value,
        ])
        ->assertSessionHasErrors('role');

    expect(User::where('email', 'alex@example.com')->exists())->toBeFalse();
});

test('a duplicate email is rejected', function () {
    $admin = actingAsRole(Role::SuperAdmin);
    User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'name' => 'Alex Marlow',
            'email' => 'taken@example.com',
            'password' => 'correct-horse-battery-staple',
            'role' => Role::Viewer->value,
        ])
        ->assertSessionHasErrors('email');
});

test('a weak password is rejected', function () {
    $admin = actingAsRole(Role::SuperAdmin);

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'name' => 'Alex Marlow',
            'email' => 'alex@example.com',
            'password' => 'short',
            'role' => Role::Viewer->value,
        ])
        ->assertSessionHasErrors('password');

    expect(User::where('email', 'alex@example.com')->exists())->toBeFalse();
});

test('guests cannot create users', function () {
    $this->post(route('users.store'), [
        'name' => 'Alex Marlow',
        'email' => 'alex@example.com',
        'password' => 'correct-horse-battery-staple',
        'role' => Role::Viewer->value,
    ])->assertRedirect(route('login'));
});
