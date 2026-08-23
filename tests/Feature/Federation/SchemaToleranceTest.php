<?php

use App\Enums\Role;
use App\Models\FederationKey;
use App\Models\FederationPartner;
use App\Models\ListingCopy;
use App\Models\User;
use App\Services\Federation\ImportService;
use App\Services\Federation\SyncService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['openyacht.domain' => 'this-node.example']);
    FederationKey::factory()->create();
});

/**
 * A payload from a partner on an older or drifted schema revision: renamed
 * fields still under their old names, retired vocabulary slugs, unknown
 * extras (x_-prefixed and not), wrong scalar types, and whole blocks
 * missing. Unknown fields MUST be ignored (api-design.md; API-8), and a
 * consumer must never error out on a payload it can partially read.
 *
 * @return array<string, mixed>
 */
function driftedListingItem(string $domain): array
{
    return [
        'id' => "https://{$domain}/openyacht/v1/listings/drift-1",
        'type' => 'sale',
        'status' => 'active',
        'updated_at' => '2026-08-19T16:05:00Z',
        'listing' => [
            'name' => 'SCHEMA DRIFT',
            'price' => ['amount' => 4500000, 'currency' => 'EUR'],
            'location' => 'Palma de Mallorca',
        ],
        'vessel' => [
            'builder' => ['name' => 'Benetti', 'slug' => 'benetti'],
            'loa_m' => '40.5',
        ],
        'specifications' => [
            'sail_power' => 'power',
            'category' => ['name' => 'Motor Yachts', 'slug' => 'motor-yachts'],
            'made_up_field' => ['deeply' => ['nested' => true]],
        ],
        'descriptions' => 'not-an-array',
        'x_partner_private' => 'opaque extension data',
        'completely_unknown_block' => ['answer' => 42],
        'usage' => [
            'display' => true,
            'attribution_required' => true,
            'attribution_text' => 'Courtesy of Partner',
            'expires_with_listing' => true,
        ],
    ];
}

test('a drifted or broken payload syncs, imports, and renders without erroring', function () {
    $this->seed(RoleSeeder::class);

    $partner = FederationPartner::factory()->verified()->create(['domain' => 'openyacht.partner.example']);

    Http::fake([
        'openyacht.partner.example/openyacht/v1/listings?*' => Http::response([
            'data' => [driftedListingItem('openyacht.partner.example')],
            'meta' => ['generated_at' => '2026-08-21T12:00:00Z', 'protocol_version' => '1.0'],
        ]),
    ]);

    // Sync stores the copy verbatim — unknown and drifted fields included.
    $result = app(SyncService::class)->sync($partner);

    expect($result->created)->toBe(1);

    $copy = ListingCopy::query()->firstOrFail();

    expect($copy->name)->toBe('SCHEMA DRIFT')
        ->and($copy->payload['x_partner_private'])->toBe('opaque extension data')
        ->and($copy->payload['specifications']['sail_power'])->toBe('power');

    // Import reads only what it recognises; nothing here may throw.
    $yacht = app(ImportService::class)->import($copy);

    expect($yacht->builder_name)->toBe('Benetti')
        // listing.location is a string where an object is expected — the
        // unreadable value degrades to null, never to an exception.
        ->and($yacht->location_display)->toBeNull();

    // The admin pages render the drifted copy without a server error.
    $admin = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Admin));

    $this->actingAs($admin)->get(route('synced-listings.index'))->assertOk();
    $this->actingAs($admin)->get(route('imported-yachts.index'))->assertOk();
    $this->actingAs($admin)->get(route('imported-yachts.show', $yacht))->assertOk();
})->group('API-8');
