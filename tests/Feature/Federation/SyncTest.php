<?php

use App\Enums\ListingStatus;
use App\Enums\Role;
use App\Models\FederationKey;
use App\Models\FederationPartner;
use App\Models\ListingCopy;
use App\Models\User;
use App\Services\Federation\PartnerAwaitingApproval;
use App\Services\Federation\SyncService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

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
            ->original->getData()['page']['props']['copies'],
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
            ->original->getData()['page']['props']['copies'],
    )->pluck('name')->all();

    expect($names('synced-listings.index'))->toBe(['SALE COPY'])
        ->and($names('synced-charter-listings.index'))->toBe(['CHARTER COPY']);
});
