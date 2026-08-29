<?php

use App\Enums\Audience;
use App\Enums\FieldGroup;
use App\Enums\Role;
use App\Enums\SharingScope;
use App\Enums\VisibilityTransition;
use App\Models\FederationPartner;
use App\Models\ListingCopy;
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

test('an everyone audience keeps explicit picks for curated partners', function () {
    $editor = sharingActor(Role::Editor);
    $yacht = SaleYacht::factory()->active()->create();
    $curated = FederationPartner::factory()->verified()->curated()->create();
    FederationPartner::factory()->verified()->create();

    $this->actingAs($editor)
        ->put(route('yachts.audience.update', $yacht), [
            'audience' => 'everyone',
            'partner_ids' => [$curated->id],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // The pick persists under everyone (additive), and only the curated
    // partner's view changed — standard partners already received it.
    expect($yacht->refresh()->audience)->toBe(Audience::Everyone)
        ->and($yacht->audiencePartners()->pluck('federation_partners.id')->all())->toBe([$curated->id])
        ->and(VisibilityEvent::query()->pluck('federation_partner_id')->all())->toBe([$curated->id]);
});

test('hiding a listing leaves its stored selection untouched', function () {
    $editor = sharingActor(Role::Editor);
    $yacht = SaleYacht::factory()->active()->create();
    $partner = FederationPartner::factory()->verified()->create();

    $this->actingAs($editor)
        ->put(route('yachts.audience.update', $yacht), [
            'audience' => 'selected',
            'partner_ids' => [$partner->id],
        ])
        ->assertRedirect();

    $this->actingAs($editor)
        ->put(route('yachts.audience.update', $yacht), ['audience' => 'none'])
        ->assertRedirect();

    // A none audience hides everything regardless; keeping the selection
    // means restoring the audience restores exactly the same partners.
    expect($yacht->refresh()->audience)->toBe(Audience::None)
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
            'acceptance_policy' => 'accept_all',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($group->members()->pluck('federation_partners.id')->all())->toBe([$partner->id])
        ->and($group->refresh()->acceptance_policy?->value)->toBe('accept_all')
        ->and($partner->refresh()->effectiveAcceptancePolicy()->value)->toBe('accept_all');

    $this->actingAs($superAdmin)
        ->delete(route('partner-groups.destroy', $group))
        ->assertRedirect();

    expect(PartnerGroup::count())->toBe(0);
});

test('loosening a policy publishes the queued backlog immediately', function () {
    $superAdmin = sharingActor(Role::SuperAdmin);
    $partner = FederationPartner::factory()->verified()->create();
    $copy = ListingCopy::factory()->create([
        'federation_partner_id' => $partner->id,
        'payload' => ['listing' => ['name' => 'QUEUED'], 'usage' => ['display' => true]],
    ]);
    $group = PartnerGroup::factory()->create();
    $group->members()->attach($partner);

    // Setting the trusted group to auto-publish must publish what is
    // already queued, not wait for each listing's next upstream change.
    $this->actingAs($superAdmin)
        ->put(route('partner-groups.update', $group), [
            'name' => $group->name,
            'member_ids' => [$partner->id],
            'acceptance_policy' => 'accept_all',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($copy->refresh()->import()->exists())->toBeTrue()
        ->and($copy->import->auto_published_at)->not->toBeNull();
});

test('sharing scope is managed with the federation permission', function () {
    $superAdmin = sharingActor(Role::SuperAdmin);
    $editor = sharingActor(Role::Editor);
    $partner = FederationPartner::factory()->verified()->create();
    $yacht = SaleYacht::factory()->active()->create();

    $this->actingAs($editor)
        ->put(route('partners.sharing-scope.update', $partner), ['sharing_scope' => 'curated'])
        ->assertForbidden();

    $this->actingAs($superAdmin)
        ->put(route('partners.sharing-scope.update', $partner), ['sharing_scope' => 'curated'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // Narrowing tombstones what the partner saw only through the
    // everyone audience — the transition is what its next poll replays.
    $event = VisibilityEvent::query()->firstWhere('listing_uuid', $yacht->uuid);

    expect($partner->refresh()->sharing_scope)->toBe(SharingScope::Curated)
        ->and($event)->not->toBeNull()
        ->and($event->federation_partner_id)->toBe($partner->id)
        ->and($event->event)->toBe(VisibilityTransition::Hidden);
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
