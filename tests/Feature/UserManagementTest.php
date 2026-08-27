<?php

use App\Enums\Role;
use App\Models\User;
use App\Notifications\UserInvitation;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();
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
            'role' => Role::Broker->value,
        ])
        ->assertRedirect();

    $created = User::where('email', 'alex@example.com')->firstOrFail();

    expect($created->name)->toBe('Alex Marlow')
        ->and($created->hasRole(Role::Broker))->toBeTrue();

    Notification::assertSentTo($created, UserInvitation::class);
});

test('the invitation carries a working password-reset token', function () {
    $admin = actingAsRole(Role::SuperAdmin);

    $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Alex Marlow',
        'email' => 'alex@example.com',
        'role' => Role::Viewer->value,
    ]);

    $created = User::where('email', 'alex@example.com')->firstOrFail();

    Notification::assertSentTo($created, UserInvitation::class, function (UserInvitation $notification) use ($created) {
        return Password::broker()
            ->tokenExists($created, $notification->token);
    });
});

test('an invited user cannot be logged into before accepting', function () {
    $admin = actingAsRole(Role::SuperAdmin);

    $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Alex Marlow',
        'email' => 'alex@example.com',
        'role' => Role::Viewer->value,
    ]);

    $created = User::where('email', 'alex@example.com')->firstOrFail();

    expect($created->password)->not->toBeEmpty()
        ->and(Hash::check('', $created->password))->toBeFalse();
});

test('a created user is marked verified because an administrator vouched for the address', function () {
    $admin = actingAsRole(Role::SuperAdmin);

    $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Alex Marlow',
        'email' => 'alex@example.com',
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
            'role' => Role::Viewer->value,
        ])
        ->assertSessionHasErrors('email');
});

test('an invalid email is rejected', function () {
    $admin = actingAsRole(Role::SuperAdmin);

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'name' => 'Alex Marlow',
            'email' => 'not-an-email',
            'role' => Role::Viewer->value,
        ])
        ->assertSessionHasErrors('email');

    expect(User::where('name', 'Alex Marlow')->exists())->toBeFalse();
});

test('guests cannot create users', function () {
    $this->post(route('users.store'), [
        'name' => 'Alex Marlow',
        'email' => 'alex@example.com',
        'role' => Role::Viewer->value,
    ])->assertRedirect(route('login'));
});
