<?php

use App\Enums\ListingStatus;
use App\Enums\Role;
use App\Models\FederationKey;
use App\Models\FederationPartner;
use App\Models\ImportedYacht;
use App\Models\ListingCopy;
use App\Models\User;
use App\Services\Federation\PartnerAwaitingApproval;
use App\Services\Federation\SyncService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    config(['openyacht.domain' => 'this-node.example']);
    FederationKey::factory()->create();
});

function listingItem(string $domain, string $uuid, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => "https://{$domain}/openyacht/v1/listings/{$uuid}",
        'type' => 'sale',
        'status' => 'active',
        'updated_at' => '2026-08-19T16:05:00Z',
        'listing' => ['name' => 'OASIS'],
        'usage' => ['display' => true, 'attribution_required' => true, 'attribution_text' => 'Courtesy of Partner', 'expires_with_listing' => true],
    ], $overrides);
}

function listingsResponse(array $data, ?string $nextCursor = null): array
{
    return [
        'data' => $data,
        'meta' => array_filter([
            'next_cursor' => $nextCursor,
            'generated_at' => '2026-08-21T12:00:00Z',
            'protocol_version' => '1.0',
        ]),
    ];
}

test('a cold sync pulls every page and stores copies with provenance', function () {
    $partner = FederationPartner::factory()->verified()->create(['domain' => 'openyacht.partner.example']);

    Http::fake([
        'openyacht.partner.example/openyacht/v1/listings?*cursor=page2*' => Http::response(
            listingsResponse([listingItem('openyacht.partner.example', 'uuid-2', ['listing' => ['name' => 'BLUE SKY']])]),
        ),
        'openyacht.partner.example/openyacht/v1/listings?*' => Http::response(
            listingsResponse([listingItem('openyacht.partner.example', 'uuid-1')], nextCursor: 'page2'),
        ),
    ]);

    $result = app(SyncService::class)->sync($partner);

    expect($result->created)->toBe(2)
        ->and(ListingCopy::count())->toBe(2);

    $copy = ListingCopy::query()->where('name', 'OASIS')->firstOrFail();

    expect($copy->canonical_uri)->toBe('https://openyacht.partner.example/openyacht/v1/listings/uuid-1')
        ->and($copy->authority_domain)->toBe('openyacht.partner.example')
        ->and($copy->provenance()['signature_verified'])->toBeTrue()
        ->and($copy->provenance()['received_at'])->not->toBeNull();

    expect($partner->refresh()->last_synced_at?->format('Y-m-d\TH:i:s\Z'))->toBe('2026-08-21T12:00:00Z')
        ->and($partner->consecutive_failures)->toBe(0);
})->group('ID-2', 'ID-3', 'API-2');

test('a charter listing syncs with its wire type and verbatim charter block', function () {
    $partner = FederationPartner::factory()->verified()->create(['domain' => 'openyacht.partner.example']);

    $charterBlock = [
        'rates' => [[
            'season' => 'summer', 'rate_type' => 'weekly',
            'amount_min' => '125000', 'amount_max' => '135000', 'currency' => 'EUR',
            'contract_terms' => 'MYBA', 'apa_percent' => 30, 'vat_percent' => null,
            'valid_from' => '2026-05-01', 'valid_to' => '2026-09-30',
        ]],
        'operating_areas' => [['name' => 'Western Mediterranean', 'slug' => 'western-mediterranean', 'season' => 'summer']],
        'summer_base_port' => 'Palma de Mallorca',
        'winter_base_port' => null,
        'crew' => [],
    ];

    Http::fake([
        'openyacht.partner.example/openyacht/v1/listings?*' => Http::response(
            listingsResponse([listingItem('openyacht.partner.example', 'uuid-charter', [
                'type' => 'charter',
                'listing' => ['name' => 'CHARTER COPY', 'price' => null],
                'charter' => $charterBlock,
            ])]),
        ),
    ]);

    app(SyncService::class)->sync($partner);

    $copy = ListingCopy::query()->firstOrFail();

    // The wire type is stored as sent, and the charter block is kept
    // verbatim in the payload — the consumer never reshapes it. (Values,
    // not key order: MySQL normalises JSON object key order on storage.)
    expect($copy->type)->toBe('charter')
        ->and(data_get($copy->payload, 'charter'))->toEqualCanonicalizing($charterBlock)
        ->and(data_get($copy->payload, 'listing.price'))->toBeNull();
})->group('ID-3', 'LS-1');

test('sync requests are signed federation requests', function () {
    $partner = FederationPartner::factory()->verified()->create(['domain' => 'openyacht.partner.example']);

    Http::fake([
        'openyacht.partner.example/*' => Http::response(listingsResponse([])),
    ]);

    app(SyncService::class)->sync($partner);

    Http::assertSent(fn ($request) => $request->hasHeader('X-OpenYacht-Signature')
        && $request->hasHeader('X-OpenYacht-Key')
        && $request->hasHeader('X-OpenYacht-Timestamp')
        && $request->header('X-OpenYacht-Node')[0] === 'this-node.example');
})->group('FP-6');

test('an incremental sync polls updated_since and applies updates and tombstones', function () {
    $partner = FederationPartner::factory()->verified()->create([
        'domain' => 'openyacht.partner.example',
        'last_synced_at' => Carbon::parse('2026-08-01T00:00:00Z'),
    ]);

    $existing = ListingCopy::factory()->for($partner, 'partner')->create([
        'federation_partner_id' => $partner->id,
        'canonical_uri' => 'https://openyacht.partner.example/openyacht/v1/listings/uuid-1',
        'authority_domain' => 'openyacht.partner.example',
        'name' => 'OLD NAME',
    ]);

    $tombstoneTarget = ListingCopy::factory()->for($partner, 'partner')->create([
        'federation_partner_id' => $partner->id,
        'canonical_uri' => 'https://openyacht.partner.example/openyacht/v1/listings/uuid-2',
        'authority_domain' => 'openyacht.partner.example',
    ]);

    Http::fake([
        'openyacht.partner.example/*' => Http::response(listingsResponse([
            listingItem('openyacht.partner.example', 'uuid-1', ['listing' => ['name' => 'NEW NAME']]),
            [
                'id' => 'https://openyacht.partner.example/openyacht/v1/listings/uuid-2',
                'tombstone' => true,
                'status' => 'withdrawn',
                'updated_at' => '2026-08-21T11:00:00Z',
            ],
        ])),
    ]);

    $result = app(SyncService::class)->sync($partner);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'updated_since=2026-08-01T00%3A00%3A00Z'));

    expect($result->updated)->toBe(1)
        ->and($result->tombstoned)->toBe(1)
        ->and($existing->refresh()->name)->toBe('NEW NAME')
        ->and($tombstoneTarget->refresh()->status)->toBe(ListingStatus::Withdrawn)
        ->and($tombstoneTarget->tombstoned_at)->not->toBeNull();
})->group('API-2', 'API-3', 'ID-7');

test('a failed sync increments consecutive failures and backs off exponentially', function () {
    $partner = FederationPartner::factory()->verified()->create(['domain' => 'openyacht.partner.example']);

    Http::fake([
        'openyacht.partner.example/*' => Http::response(null, 500),
    ]);

    $sync = app(SyncService::class);

    expect(fn () => $sync->sync($partner))->toThrow(Exception::class)
        ->and($partner->refresh()->consecutive_failures)->toBe(1)
        ->and($sync->isDue($partner))->toBeFalse();

    $this->travel(2)->hours();

    expect($sync->isDue($partner->refresh()))->toBeTrue();
});

test('a partner that has not approved us yet is not counted as a failure', function () {
    $partner = FederationPartner::factory()->verified()->create(['domain' => 'openyacht.partner.example']);

    Http::fake([
        'openyacht.partner.example/*' => Http::response([
            'error' => [
                'code' => 'PARTNER_PROVISIONAL',
                'message' => 'Partnership is pending approval; no listings are shared yet.',
            ],
        ], 403),
    ]);

    $sync = app(SyncService::class);

    // PARTNER_PROVISIONAL is "authenticated but not yet approved"
    // (api-design.md §Errors) — the request was delivered and verified, so
    // backing off would punish both sides for a correct handshake and
    // delay the first sync long after approval finally lands.
    expect(fn () => $sync->sync($partner))->toThrow(PartnerAwaitingApproval::class)
        ->and($partner->refresh()->consecutive_failures)->toBe(0)
        ->and($sync->isDue($partner))->toBeTrue();
})->group('FP-13');

test('other 403 responses still count as failures', function () {
    $partner = FederationPartner::factory()->verified()->create(['domain' => 'openyacht.partner.example']);

    Http::fake([
        'openyacht.partner.example/*' => Http::response([
            'error' => ['code' => 'PARTNER_BLOCKED', 'message' => 'This partner is blocked.'],
        ], 403),
    ]);

    $sync = app(SyncService::class);

    expect(fn () => $sync->sync($partner))->toThrow(Exception::class)
        ->and($partner->refresh()->consecutive_failures)->toBe(1)
        ->and($sync->isDue($partner))->toBeFalse();
})->group('FP-13');

test('copies of tombstoned listings are not tombstoned twice', function () {
    $partner = FederationPartner::factory()->verified()->create(['domain' => 'openyacht.partner.example']);

    $copy = ListingCopy::factory()->for($partner, 'partner')->tombstoned()->create([
        'federation_partner_id' => $partner->id,
        'canonical_uri' => 'https://openyacht.partner.example/openyacht/v1/listings/uuid-1',
    ]);

    $originalTombstonedAt = $copy->tombstoned_at;

    $this->travel(1)->hour();

    Http::fake([
        'openyacht.partner.example/*' => Http::response(listingsResponse([
            [
                'id' => 'https://openyacht.partner.example/openyacht/v1/listings/uuid-1',
                'tombstone' => true,
                'status' => 'withdrawn',
                'updated_at' => '2026-08-21T11:00:00Z',
            ],
        ])),
    ]);

    app(SyncService::class)->sync($partner);

    expect($copy->refresh()->tombstoned_at->equalTo($originalTombstonedAt))->toBeTrue();
});

test('the synced listing filters read the copy payload, floats included', function () {
    $this->seed(RoleSeeder::class);

    $partner = FederationPartner::factory()->verified()->create(['domain' => 'openyacht.partner.example']);

    Http::fake([
        'openyacht.partner.example/openyacht/v1/listings?*' => Http::response(listingsResponse([
            listingItem('openyacht.partner.example', 'uuid-1', [
                'listing' => ['name' => 'BIG ONE'],
                'vessel' => ['loa_m' => 42.5],
                'specifications' => ['category' => ['name' => 'Flybridge', 'slug' => 'flybridge']],
            ]),
            listingItem('openyacht.partner.example', 'uuid-2', [
                'listing' => ['name' => 'SMALL ONE'],
                'vessel' => ['loa_m' => 12.0],
            ]),
        ])),
    ]);

    app(SyncService::class)->sync($partner);

    $admin = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Admin));

    $names = fn (array $params): array => collect(
        $this->actingAs($admin)
            ->get(route('synced-listings.index', $params))
            ->original->getData()['page']['props']['copies']['data'],
    )->pluck('name')->all();

    // A fractional bound regression-tests the PDO float-as-string binding
    // trap against SQLite's strict storage-class comparisons.
    expect($names(['loa_min' => 30.5]))->toBe(['BIG ONE'])
        ->and($names(['loa_max' => 30]))->toBe(['SMALL ONE'])
        ->and($names(['category' => 'flybridge']))->toBe(['BIG ONE']);
});

test('sale and charter copies are never mixed in one synced list', function () {
    $this->seed(RoleSeeder::class);

    ListingCopy::factory()->create(['name' => 'SALE COPY']);
    ListingCopy::factory()->create([
        'name' => 'CHARTER COPY',
        'type' => 'charter',
        'canonical_uri' => 'https://openyacht.partner.example/openyacht/v1/listings/uuid-charter',
    ]);

    $admin = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Admin));

    $names = fn (string $routeName): array => collect(
        $this->actingAs($admin)
            ->get(route($routeName))
            ->original->getData()['page']['props']['copies']['data'],
    )->pluck('name')->all();

    expect($names('synced-listings.index'))->toBe(['SALE COPY'])
        ->and($names('synced-charter-listings.index'))->toBe(['CHARTER COPY']);
});

test('the synced list shows only copies not yet imported by default, with a filter for the rest', function () {
    $this->seed(RoleSeeder::class);

    $partner = FederationPartner::factory()->verified()->create();
    ListingCopy::factory()->for($partner, 'partner')->create(['name' => 'ON OFFER']);
    $taken = ListingCopy::factory()->for($partner, 'partner')->create(['name' => 'ALREADY IMPORTED']);
    ImportedYacht::factory()->create(['listing_copy_id' => $taken->id]);

    $admin = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Admin));

    $props = fn (array $params = []): array => $this->actingAs($admin)
        ->get(route('synced-listings.index', $params))
        ->assertOk()
        ->original->getData()['page']['props'];

    $names = fn (array $props): array => collect($props['copies']['data'])->pluck('name')->sort()->values()->all();

    // The screen is the review queue: what is on offer and not taken.
    expect($names($props()))->toBe(['ON OFFER'])
        ->and($props()['importState'])->toBe('pending')
        ->and($names($props(['imported' => 'imported'])))->toBe(['ALREADY IMPORTED'])
        ->and($names($props(['imported' => 'all'])))->toBe(['ALREADY IMPORTED', 'ON OFFER'])
        // An unknown value falls back to the default rather than erroring.
        ->and($names($props(['imported' => 'bogus'])))->toBe(['ON OFFER']);
});

test('the synced list pages by 24 and carries the filters across pages', function () {
    $this->seed(RoleSeeder::class);

    $partner = FederationPartner::factory()->verified()->create(['node_name' => 'Paged Partner']);

    // 25 copies, updated a minute apart so the ordering is deterministic:
    // the newest 24 fill page one and the oldest lands alone on page two.
    foreach (range(1, 25) as $index) {
        ListingCopy::factory()->for($partner, 'partner')->create([
            'name' => sprintf('COPY %02d', $index),
            'listing_updated_at' => now()->subMinutes(26 - $index),
        ]);
    }

    $admin = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Admin));

    $page = fn (array $params): array => $this->actingAs($admin)
        ->get(route('synced-listings.index', $params))
        ->assertOk()
        ->original->getData()['page']['props']['copies'];

    $first = $page(['partner' => $partner->id]);
    $second = $page(['partner' => $partner->id, 'page' => 2]);

    expect(collect($first['data'])->pluck('name')->all())->toHaveCount(24)
        ->and(collect($first['data'])->pluck('name')->first())->toBe('COPY 25')
        ->and($first['total'])->toBe(25)
        ->and($first['next_page_url'])->toContain('page=2')
        // withQueryString: the active filter survives the page link.
        ->and($first['next_page_url'])->toContain("partner={$partner->id}")
        ->and(collect($second['data'])->pluck('name')->all())->toBe(['COPY 01'])
        ->and($second['next_page_url'])->toBeNull();
});

test('the synced list filters by partner and offers only partners with copies of that type', function () {
    $this->seed(RoleSeeder::class);

    $alpha = FederationPartner::factory()->verified()->create(['node_name' => 'Alpha Yachts']);
    $beta = FederationPartner::factory()->verified()->create(['node_name' => null, 'domain' => 'openyacht.beta.example']);
    $charterOnly = FederationPartner::factory()->verified()->create(['node_name' => 'Charter House']);
    FederationPartner::factory()->verified()->create(['node_name' => 'Nothing Shared']);

    ListingCopy::factory()->for($alpha, 'partner')->create(['name' => 'ALPHA ONE']);
    ListingCopy::factory()->for($alpha, 'partner')->create(['name' => 'ALPHA TWO']);
    ListingCopy::factory()->for($beta, 'partner')->create(['name' => 'BETA ONE']);
    ListingCopy::factory()->for($charterOnly, 'partner')->create(['name' => 'CHARTER ONE', 'type' => 'charter']);

    $admin = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Admin));

    $props = fn (array $params = []): array => $this->actingAs($admin)
        ->get(route('synced-listings.index', $params))
        ->assertOk()
        ->original->getData()['page']['props'];

    $unfiltered = $props();

    expect(collect($unfiltered['copies']['data'])->pluck('name')->all())
        ->toEqualCanonicalizing(['ALPHA ONE', 'ALPHA TWO', 'BETA ONE'])
        // Labelled by node name, falling back to the domain; sale-only
        // partners are listed, the charter-only and idle ones are not.
        ->and(collect($unfiltered['partners'])->pluck('label', 'id')->all())
        ->toBe([$alpha->id => 'Alpha Yachts', $beta->id => 'openyacht.beta.example'])
        ->and($unfiltered['filters']['partner'])->toBe('');

    $filtered = $props(['partner' => $alpha->id]);

    expect(collect($filtered['copies']['data'])->pluck('name')->all())
        ->toEqualCanonicalizing(['ALPHA ONE', 'ALPHA TWO'])
        ->and($filtered['filters']['partner'])->toBe((string) $alpha->id);

    // A non-numeric partner is ignored rather than erroring.
    expect(collect($props(['partner' => 'nope'])['copies']['data'])->pluck('name')->all())
        ->toEqualCanonicalizing(['ALPHA ONE', 'ALPHA TWO', 'BETA ONE']);
});

test('sync records federation activity for status changes, tombstones, and removals', function () {
    $partner = FederationPartner::factory()->verified()->create([
        'domain' => 'openyacht.partner.example',
        'last_synced_at' => Carbon::parse('2026-08-01T00:00:00Z'),
    ]);

    // One copy whose status changes upstream (active -> under_offer)...
    ListingCopy::factory()->for($partner, 'partner')->create([
        'federation_partner_id' => $partner->id,
        'canonical_uri' => 'https://openyacht.partner.example/openyacht/v1/listings/uuid-1',
        'authority_domain' => 'openyacht.partner.example',
        'name' => 'SEA BREEZE',
        'status' => ListingStatus::Active,
    ]);

    // ...and one that is imported and then withdrawn (sold) upstream.
    $sold = ListingCopy::factory()->for($partner, 'partner')->create([
        'federation_partner_id' => $partner->id,
        'canonical_uri' => 'https://openyacht.partner.example/openyacht/v1/listings/uuid-2',
        'authority_domain' => 'openyacht.partner.example',
        'name' => 'BLUE MARLIN',
        'status' => ListingStatus::Active,
    ]);
    ImportedYacht::factory()->create([
        'listing_copy_id' => $sold->id,
        'name' => 'BLUE MARLIN',
    ]);

    Http::fake([
        'openyacht.partner.example/*' => Http::response(listingsResponse([
            listingItem('openyacht.partner.example', 'uuid-1', ['status' => 'under_offer']),
            [
                'id' => 'https://openyacht.partner.example/openyacht/v1/listings/uuid-2',
                'tombstone' => true,
                'status' => 'sold',
                'updated_at' => '2026-08-21T11:00:00Z',
            ],
        ])),
    ]);

    app(SyncService::class)->sync($partner);

    // Evidentiary per-listing events are audit ('federation', kept
    // forever); the per-run summary is the only prunable one ('sync').
    $audit = Activity::query()->where('log_name', 'federation')->pluck('event')->all();
    $sync = Activity::query()->where('log_name', 'sync')->pluck('event')->all();

    expect($audit)->toContain('listing_status_changed')
        ->toContain('listing_tombstoned')
        ->toContain('import_removed')
        ->and($sync)->toContain('sync_completed');
});

test('an idle sync that changes nothing writes no activity', function () {
    $partner = FederationPartner::factory()->verified()->create([
        'domain' => 'openyacht.partner.example',
    ]);

    Http::fake([
        'openyacht.partner.example/*' => Http::response(listingsResponse([])),
    ]);

    app(SyncService::class)->sync($partner);

    expect(Activity::query()->where('log_name', 'sync')->count())->toBe(0);
});
