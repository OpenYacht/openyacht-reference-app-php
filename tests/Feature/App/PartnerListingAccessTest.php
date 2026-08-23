<?php

use App\Enums\Role;
use App\Models\ImportedYacht;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function partnerListingActor(?Role $role = null): User
{
    return tap(User::factory()->create(), function (User $user) use ($role): void {
        if ($role !== null) {
            $user->assignRole($role);
        }
    });
}

test('partner listing pages require a listings permission', function (?Role $role, bool $allowed) {
    $actor = partnerListingActor($role);

    $this->actingAs($actor)
        ->get(route('synced-listings.index'))
        ->assertStatus($allowed ? 200 : 403);

    $this->actingAs($actor)
        ->get(route('imported-yachts.index'))
        ->assertStatus($allowed ? 200 : 403);
})->with([
    'no role' => [null, false],
    'viewer' => [Role::Viewer, false],
    'broker' => [Role::Broker, true],
    'editor' => [Role::Editor, true],
    'super admin' => [Role::SuperAdmin, true],
]);

test('an imported yacht detail page requires a listings permission', function () {
    $yacht = ImportedYacht::factory()->create();

    $this->actingAs(partnerListingActor())
        ->get(route('imported-yachts.show', $yacht))
        ->assertForbidden();

    $this->actingAs(partnerListingActor(Role::Editor))
        ->get(route('imported-yachts.show', $yacht))
        ->assertOk();
});
