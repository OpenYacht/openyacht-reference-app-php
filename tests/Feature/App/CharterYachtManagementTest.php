<?php

use App\Enums\ListingStatus;
use App\Enums\Role;
use App\Models\CharterYacht;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    config(['openyacht.domain' => 'this-node.example']);
    $this->seed(RoleSeeder::class);
});

function charterActor(Role $role): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
}

test('an editor can create a charter yacht with rates, areas, and crew', function () {
    $editor = charterActor(Role::Editor);

    $this->actingAs($editor)
        ->post(route('charter-yachts.store'), [
            'name' => 'FICTIONAL CHARTER',
            'builder_slug' => 'benetti',
            'year_built' => 2019,
            'loa_m' => 45.0,
            'location_display' => 'Palma de Mallorca, Spain',
            'specifications' => ['power_or_sail' => 'power'],
            'rates' => [[
                'season' => 'summer',
                'rate_type' => 'weekly',
                'amount_min' => '125000',
                'amount_max' => '135000',
                'currency' => 'EUR',
                'contract_terms' => 'MYBA',
                'apa_percent' => 30,
                'vat_percent' => null,
                'valid_from' => '2026-05-01',
                'valid_to' => '2026-09-30',
            ]],
            'operating_areas' => [
                ['name' => 'whatever', 'slug' => 'western-mediterranean', 'season' => 'summer'],
            ],
            'summer_base_port' => 'Palma de Mallorca',
            'crew' => [
                ['role' => 'Captain', 'name' => 'Fictional F. Skipper', 'nationality' => 'British', 'tba' => false],
            ],
            'crew_attested' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $yacht = CharterYacht::query()->firstOrFail();

    expect($yacht->status)->toBe(ListingStatus::Draft)
        ->and($yacht->uuid)->not->toBeNull()
        ->and($yacht->rates[0]['amount_min'])->toBe('125000')
        // A registry slug carries the canonical registry name.
        ->and($yacht->operating_areas[0])->toBe(['name' => 'Western Mediterranean', 'slug' => 'western-mediterranean', 'season' => 'summer'])
        ->and($yacht->crew_attested_at)->not->toBeNull()
        ->and($yacht->assigned_broker_id)->toBe($editor->id);
});

test('the charter list applies the shared filter set', function () {
    $editor = charterActor(Role::Editor);

    $gale = CharterYacht::factory()->create([
        'name' => 'SUMMER GALE',
        'specifications' => ['power_or_sail' => 'sail'],
    ]);
    $gale->vessel->update(['builder_name' => 'Nautor Swan', 'year_built' => 2005]);

    $calm = CharterYacht::factory()->create([
        'name' => 'STILL WATERS',
        'specifications' => ['power_or_sail' => 'power'],
    ]);
    $calm->vessel->update(['builder_name' => 'Benetti', 'year_built' => 2022]);

    $names = fn (array $params): array => collect(
        $this->actingAs($editor)
            ->get(route('charter-yachts.index', $params))
            ->original->getData()['page']['props']['yachts'],
    )->pluck('name')->all();

    expect($names([]))->toHaveCount(2)
        ->and($names(['builder' => 'Nautor Swan']))->toBe(['SUMMER GALE'])
        ->and($names(['year_min' => 2010]))->toBe(['STILL WATERS'])
        ->and($names(['power_sail' => 'sail']))->toBe(['SUMMER GALE']);
});

test('an invented destination slug is rejected; an unlisted cruising ground keeps a null slug', function () {
    $this->actingAs(charterActor(Role::Editor))
        ->post(route('charter-yachts.store'), [
            'name' => 'BAD DESTINATION',
            'builder_slug' => 'benetti',
            'specifications' => ['power_or_sail' => 'power'],
            'operating_areas' => [['name' => 'Made Up Sea', 'slug' => 'made-up-sea']],
        ])
        ->assertSessionHasErrors('operating_areas.0.slug');

    $this->actingAs(charterActor(Role::Editor))
        ->post(route('charter-yachts.store'), [
            'name' => 'UNLISTED GROUND',
            'builder_slug' => 'benetti',
            'specifications' => ['power_or_sail' => 'power'],
            'operating_areas' => [['name' => 'Ionian Sea', 'slug' => null]],
        ])
        ->assertSessionHasNoErrors();

    expect(CharterYacht::query()->firstOrFail()->operating_areas[0])
        ->toBe(['name' => 'Ionian Sea', 'slug' => null, 'season' => null]);
})->group('LS-11');

test('money in rates must be digit strings', function () {
    $this->actingAs(charterActor(Role::Editor))
        ->post(route('charter-yachts.store'), [
            'name' => 'BAD MONEY',
            'builder_slug' => 'benetti',
            'specifications' => ['power_or_sail' => 'power'],
            'rates' => [[
                'season' => 'summer',
                'rate_type' => 'weekly',
                'amount_min' => '125,000',
                'currency' => 'EUR',
            ]],
        ])
        ->assertSessionHasErrors('rates.0.amount_min');
})->group('API-12');

test('a TBA crew member is stored with the personal fields nulled', function () {
    $this->actingAs(charterActor(Role::Editor))
        ->post(route('charter-yachts.store'), [
            'name' => 'TBA CREW',
            'builder_slug' => 'benetti',
            'specifications' => ['power_or_sail' => 'power'],
            'crew' => [
                ['role' => 'Chef', 'name' => 'Should Vanish', 'nationality' => 'French', 'tba' => true],
            ],
        ])
        ->assertSessionHasNoErrors();

    // Values, not key order — MySQL normalises JSON object key order.
    expect(CharterYacht::query()->firstOrFail()->crew[0])->toEqualCanonicalizing([
        'role' => 'Chef', 'name' => null, 'nationality' => null,
        'bio' => null, 'photo_url' => null, 'tba' => true,
    ]);
});

test('unticking the attestation revokes it; re-ticking keeps the original timestamp', function () {
    $editor = charterActor(Role::Editor);
    $yacht = CharterYacht::factory()->create(['assigned_broker_id' => $editor->id]);
    $original = $yacht->crew_attested_at;

    $update = fn (bool $attested) => $this->actingAs($editor)->put(
        route('charter-yachts.update', $yacht),
        [
            'name' => $yacht->name,
            'builder_slug' => 'benetti',
            'specifications' => ['power_or_sail' => 'power'],
            'crew' => $yacht->crew,
            'crew_attested' => $attested,
        ],
    )->assertSessionHasNoErrors();

    $update(true);
    expect($yacht->refresh()->crew_attested_at?->toIso8601String())
        ->toBe($original?->toIso8601String());

    $update(false);
    expect($yacht->refresh()->crew_attested_at)->toBeNull();
})->group('LS-15');

test('the charter transition endpoint follows the lifecycle', function () {
    $editor = charterActor(Role::Editor);
    $yacht = CharterYacht::factory()->create();

    $this->actingAs($editor)
        ->post(route('charter-yachts.transition', $yacht), ['status' => 'active'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($yacht->refresh()->status)->toBe(ListingStatus::Active);

    $this->actingAs($editor)
        ->post(route('charter-yachts.transition', $yacht), ['status' => 'draft'])
        ->assertSessionHasErrors('status');
})->group('ID-8');

test('brokers see and edit only their own charter listings', function () {
    $broker = charterActor(Role::Broker);
    $own = CharterYacht::factory()->create(['assigned_broker_id' => $broker->id]);
    $other = CharterYacht::factory()->create();

    $response = $this->actingAs($broker)->get(route('charter-yachts.index'))->assertOk();

    $ids = collect($response->original->getData()['page']['props']['yachts'])->pluck('id');

    expect($ids->all())->toBe([$own->id]);

    $this->actingAs($broker)
        ->get(route('charter-yachts.edit', $other))
        ->assertForbidden();

    $this->actingAs($broker)
        ->get(route('charter-yachts.edit', $own))
        ->assertOk();
});

test('viewers cannot access charter yacht management', function () {
    $this->actingAs(charterActor(Role::Viewer))
        ->get(route('charter-yachts.index'))
        ->assertForbidden();
});
