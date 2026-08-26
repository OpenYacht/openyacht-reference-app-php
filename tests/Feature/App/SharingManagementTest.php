<?php

use App\Enums\Audience;
use App\Enums\FieldGroup;
use App\Enums\Role;
use App\Enums\VisibilityTransition;
use App\Models\FederationPartner;
use App\Models\PartnerGroup;
use App\Models\SaleYacht;
use App\Models\User;
use App\Models\VisibilityEvent;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function sharingActor(Role $role): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
}

test('an editor can set a listing audience from the edit screen', function () {
    $editor = sharingActor(Role::Editor);
    $yacht = SaleYacht::factory()->active()->create();
    $partner = FederationPartner::factory()->verified()->create();

    $this->actingAs($editor)
        ->put(route('yachts.audience.update', $yacht), [
            'audience' => 'selected',
            'partner_ids' => [$partner->id],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $yacht->refresh();

    expect($yacht->audience)->toBe(Audience::Selected)
        ->and($yacht->audiencePartners()->pluck('federation_partners.id')->all())->toBe([$partner->id]);
});

test('setting an audience requires the listing update permission', function () {
    $viewer = sharingActor(Role::Viewer);
    $yacht = SaleYacht::factory()->active()->create();

    $this->actingAs($viewer)
        ->put(route('yachts.audience.update', $yacht), ['audience' => 'none'])
        ->assertForbidden();
});

test('partner groups are managed with the federation permission', function () {
    $superAdmin = sharingActor(Role::SuperAdmin);
    $editor = sharingActor(Role::Editor);
    $partner = FederationPartner::factory()->verified()->create();

    $this->actingAs($editor)
        ->post(route('partner-groups.store'), ['name' => 'Offices'])
        ->assertForbidden();

    $this->actingAs($superAdmin)
        ->post(route('partner-groups.store'), ['name' => 'Offices'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $group = PartnerGroup::firstWhere('name', 'Offices');

    $this->actingAs($superAdmin)
        ->put(route('partner-groups.update', $group), [
            'name' => 'Offices',
            'member_ids' => [$partner->id],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($group->members()->pluck('federation_partners.id')->all())->toBe([$partner->id]);

    $this->actingAs($superAdmin)
        ->delete(route('partner-groups.destroy', $group))
        ->assertRedirect();

    expect(PartnerGroup::count())->toBe(0);
});

test('changing partner field-group grants refreshes that partner feed', function () {
    $superAdmin = sharingActor(Role::SuperAdmin);
    $partner = FederationPartner::factory()->verified()->create();
    $yacht = SaleYacht::factory()->active()->create();

    $this->actingAs($superAdmin)
        ->put(route('partners.field-groups.update', $partner), [
            'field_groups' => [FieldGroup::Pricing->value, FieldGroup::Documents->value],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($partner->refresh()->field_groups)->toBe([FieldGroup::Pricing->value, FieldGroup::Documents->value]);

    // The refreshed event is what carries the re-gated payload to the
    // partner's next poll (API-4) — without it the grants change would
    // wait for the next content change.
    $event = VisibilityEvent::query()->firstWhere('listing_uuid', $yacht->uuid);

    expect($event)->not->toBeNull()
        ->and($event->federation_partner_id)->toBe($partner->id)
        ->and($event->event)->toBe(VisibilityTransition::Refreshed);
})->group('API-4');
