<?php

use App\Enums\ListingStatus;
use App\Enums\Role;
use App\Models\CharterYacht;
use App\Models\FederationPartner;
use App\Models\ListingCopy;
use App\Models\SaleYacht;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    config(['openyacht.domain' => 'this-node.example']);
    $this->seed(RoleSeeder::class);
});

function dashboardActor(Role $role): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
}

function dashboardProps(object $test, User $user): array
{
    return $test->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->original->getData()['page']['props'];
}

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('a super admin dashboard counts both listing types, partner copies, and partner health', function () {
    SaleYacht::factory()->active()->count(2)->create();
    SaleYacht::factory()->create();
    CharterYacht::factory()->active()->create();
    CharterYacht::factory()->terminal(ListingStatus::Withdrawn)->create();
    ListingCopy::factory()->create();
    ListingCopy::factory()->tombstoned()->create();
    FederationPartner::factory()->verified()->create(['last_ok_at' => now()->subDays(10)]);

    $props = dashboardProps($this, dashboardActor(Role::SuperAdmin));

    expect($props['fleet'])->toBe(['sale_active' => 2, 'sale_draft' => 1, 'charter_active' => 1, 'charter_draft' => 0])
        ->and($props['partnerListings'])->toBe(['synced' => 1, 'tombstoned' => 1, 'imported' => 0])
        ->and($props['federation']['verified'])->toBe(1)
        ->and($props['federation']['stale'])->toBe(1)
        ->and(collect($props['recentListings'])->pluck('type')->sort()->values()->all())
        ->toBe(['charter', 'charter', 'sale', 'sale', 'sale']);
});

test('broker dashboards count only their own listings', function () {
    $broker = dashboardActor(Role::Broker);
    SaleYacht::factory()->active()->create(['assigned_broker_id' => $broker->id]);
    SaleYacht::factory()->active()->create();
    CharterYacht::factory()->active()->create();

    $props = dashboardProps($this, $broker);

    expect($props['fleet']['sale_active'])->toBe(1)
        ->and($props['fleet']['charter_active'])->toBe(0)
        ->and($props['recentListings'])->toHaveCount(1);
});

test('a user with no role sees no widgets', function () {
    $props = dashboardProps($this, User::factory()->create());

    expect($props['fleet'])->toBeNull()
        ->and($props['partnerListings'])->toBeNull()
        ->and($props['federation'])->toBeNull()
        ->and($props['recentListings'])->toBeNull();
});
