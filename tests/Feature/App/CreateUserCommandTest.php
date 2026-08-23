<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('openyacht:create-user creates a verified user with the chosen role', function () {
    $this->artisan('openyacht:create-user')
        ->expectsQuestion('Name', 'CLI User')
        ->expectsQuestion('Email', 'cli@example.com')
        ->expectsQuestion('Password', 'secret-password-123')
        ->expectsQuestion('Role', Role::SuperAdmin->value)
        ->assertSuccessful();

    $user = User::query()->where('email', 'cli@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->hasRole(Role::SuperAdmin))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
});

test('openyacht:create-user rejects a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('openyacht:create-user')
        ->expectsQuestion('Name', 'CLI User')
        ->expectsQuestion('Email', 'taken@example.com')
        ->expectsQuestion('Password', 'secret-password-123')
        ->expectsQuestion('Role', Role::Viewer->value)
        ->assertFailed();

    expect(User::query()->where('email', 'taken@example.com')->count())->toBe(1);
});
