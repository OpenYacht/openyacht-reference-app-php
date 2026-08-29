<?php

use App\Enums\Role;
use App\Enums\VisibilityTransition;
use App\Models\CharterYacht;
use App\Models\FederationPartner;
use App\Models\PartnerGroup;
use App\Models\SaleYacht;
use App\Models\User;
use App\Models\VisibilityEvent;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function curatedActor(Role $role): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
}

test('the shared-listings picker appears only for curated partners', function () {
    $superAdmin = curatedActor(Role::SuperAdmin);
    $standard = FederationPartner::factory()->verified()->create();
    $curated = FederationPartner::factory()->verified()->curated()->create();

    $props = $this->actingAs($superAdmin)
        ->get(route('partners.show', $standard))
        ->assertOk()
        ->original->getData()['page']['props'];

    expect($props)->not->toHaveKey('sharedListings');

    $props = $this->actingAs($superAdmin)
        ->get(route('partners.show', $curated))
        ->assertOk()
        ->original->getData()['page']['props'];

    expect($props['sharedListings']['type'])->toBe('sale');
});

test('the picker shows one listing type at a time, never mixed', function () {
    $superAdmin = curatedActor(Role::SuperAdmin);
    $curated = FederationPartner::factory()->verified()->curated()->create();
    $sale = SaleYacht::factory()->active()->create(['name' => 'SALE ONLY']);
    CharterYacht::factory()->active()->create(['name' => 'CHARTER ONLY']);

    $names = fn (string $type): array => collect(
        $this->actingAs($superAdmin)
            ->get(route('partners.show', [$curated, 'listing_type' => $type]))
            ->assertOk()
            ->original->getData()['page']['props']['sharedListings']['items'],
    )->pluck('name')->all();

    expect($names('sale'))->toBe(['SALE ONLY'])
        ->and($names('charter'))->toBe(['CHARTER ONLY'])
        // The sale/charter twin hint keys on the shared vessel: none here.
        ->and($sale->vessel_id)->not->toBeNull();
});

test('a sale and charter listing of one vessel flag each other as twins', function () {
    $superAdmin = curatedActor(Role::SuperAdmin);
    $curated = FederationPartner::factory()->verified()->curated()->create();
    $sale = SaleYacht::factory()->active()->create(['name' => 'BOTH WAYS']);
    CharterYacht::factory()->active()->create([
        'name' => 'BOTH WAYS',
        'vessel_id' => $sale->vessel_id,
    ]);

    $items = collect(
        $this->actingAs($superAdmin)
            ->get(route('partners.show', $curated))
            ->assertOk()
            ->original->getData()['page']['props']['sharedListings']['items'],
    );

    expect($items->firstWhere('name', 'BOTH WAYS')['has_sister_listing'])->toBeTrue();
});

test('replacing direct shares requires the federation permission and per-type uuids', function () {
    $superAdmin = curatedActor(Role::SuperAdmin);
    $editor = curatedActor(Role::Editor);
    $curated = FederationPartner::factory()->verified()->curated()->create();
    $sale = SaleYacht::factory()->active()->create();
    $charter = CharterYacht::factory()->active()->create();

    $this->actingAs($editor)
        ->put(route('partners.shared-listings.update', $curated), [
            'type' => 'sale',
            'uuids' => [$sale->uuid],
        ])
        ->assertForbidden();

    // A charter uuid under type=sale is rejected — the two tables never
    // mix in one payload.
    $this->actingAs($superAdmin)
        ->put(route('partners.shared-listings.update', $curated), [
            'type' => 'sale',
            'uuids' => [$charter->uuid],
        ])
        ->assertSessionHasErrors('uuids.0');

    $this->actingAs($superAdmin)
        ->put(route('partners.shared-listings.update', $curated), [
            'type' => 'sale',
            'uuids' => [$sale->uuid],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($sale->audiencePartners()->pluck('federation_partners.id')->all())->toBe([$curated->id]);
});

test('removing a direct share emits no tombstone while a group still grants the listing', function () {
    $superAdmin = curatedActor(Role::SuperAdmin);
    $curated = FederationPartner::factory()->verified()->curated()->create();
    $yacht = SaleYacht::factory()->active()->create();

    $group = PartnerGroup::factory()->create();
    $group->members()->attach($curated);
    $yacht->audienceGroups()->attach($group);
    $yacht->audiencePartners()->attach($curated);

    $this->actingAs($superAdmin)
        ->put(route('partners.shared-listings.update', $curated), [
            'type' => 'sale',
            'uuids' => [],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // The direct row is gone, but the group still grants visibility — no
    // transition, no tombstone.
    expect($yacht->audiencePartners()->count())->toBe(0)
        ->and(VisibilityEvent::count())->toBe(0);
});

test('replacing direct shares diffs per listing and records only real changes', function () {
    $superAdmin = curatedActor(Role::SuperAdmin);
    $curated = FederationPartner::factory()->verified()->curated()->create();
    $kept = SaleYacht::factory()->active()->create();
    $added = SaleYacht::factory()->active()->create();
    $removed = SaleYacht::factory()->active()->create();
    $kept->audiencePartners()->attach($curated);
    $removed->audiencePartners()->attach($curated);

    $this->actingAs($superAdmin)
        ->put(route('partners.shared-listings.update', $curated), [
            'type' => 'sale',
            'uuids' => [$kept->uuid, $added->uuid],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $events = VisibilityEvent::query()->get();

    expect($events)->toHaveCount(2)
        ->and($events->firstWhere('listing_uuid', $added->uuid)->event)->toBe(VisibilityTransition::Visible)
        ->and($events->firstWhere('listing_uuid', $removed->uuid)->event)->toBe(VisibilityTransition::Hidden)
        ->and($events->firstWhere('listing_uuid', $kept->uuid))->toBeNull();
});
