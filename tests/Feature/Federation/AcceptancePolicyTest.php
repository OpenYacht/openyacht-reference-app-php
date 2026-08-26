<?php

use App\Enums\AcceptancePolicy;
use App\Models\FederationKey;
use App\Models\FederationPartner;
use App\Models\ImportedYacht;
use App\Models\ListingCopy;
use App\Models\PartnerGroup;
use App\Models\SaleYacht;
use App\Models\Vessel;
use App\Services\Federation\SyncService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['openyacht.domain' => 'this-node.example']);
    FederationKey::factory()->create();
    Bus::fake();
});

/**
 * A wire listing that passes the completeness check unless overridden.
 */
function acceptableItem(string $uuid, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => "https://openyacht.partner.example/openyacht/v1/listings/{$uuid}",
        'type' => 'sale',
        'status' => 'active',
        'updated_at' => '2026-08-19T16:05:00Z',
        'vessel' => ['builder' => ['name' => 'Benetti'], 'model' => ['name' => 'Oasis 40M'], 'year_built' => 2021, 'loa_m' => 40.8, 'hin' => null, 'imo' => null],
        'listing' => ['name' => 'OASIS', 'price' => ['amount' => '8500000', 'currency' => 'EUR']],
        'media' => ['profile' => ['url' => 'https://media.partner.example/profile.jpg', 'sha256' => null, 'caption' => null]],
        'usage' => ['display' => true, 'attribution_required' => false, 'expires_with_listing' => true],
    ], $overrides);
}

function acceptanceSync(array $items, string $policy = 'accept_complete'): FederationPartner
{
    $partner = FederationPartner::factory()->verified()->create([
        'domain' => 'openyacht.partner.example',
        'acceptance_policy' => $policy,
    ]);

    Http::fake([
        'openyacht.partner.example/openyacht/v1/listings?*' => Http::response([
            'data' => $items,
            'meta' => ['generated_at' => '2026-08-21T12:00:00Z', 'protocol_version' => '1.0'],
        ]),
    ]);

    app(SyncService::class)->sync($partner);

    return $partner;
}

test('accept_complete publishes a complete listing automatically, stamped for the review-after feed', function () {
    acceptanceSync([acceptableItem('uuid-1')]);

    $imported = ImportedYacht::query()->firstOrFail();

    expect($imported->name)->toBe('OASIS')
        ->and($imported->auto_published_at)->not->toBeNull()
        ->and($imported->imported_by_user_id)->toBeNull();
});

test('incomplete listings queue for review instead of publishing', function () {
    acceptanceSync([
        acceptableItem('uuid-1', ['media' => ['profile' => null]]),
        acceptableItem('uuid-2', ['listing' => ['price' => ['amount' => null]]]),
        acceptableItem('uuid-3', ['vessel' => ['loa_m' => null]]),
    ]);

    // Field-group gating means a legitimately shared listing can arrive
    // with pricing withheld — auto-publishing it would put POA-shaped
    // holes on a public site (LS-14).
    expect(ListingCopy::count())->toBe(3)
        ->and(ImportedYacht::count())->toBe(0);
});

test('a charter listing with rates but no asking price is complete', function () {
    acceptanceSync([acceptableItem('uuid-1', [
        'type' => 'charter',
        'listing' => ['price' => null],
        'charter' => ['rates' => [['season' => 'summer', 'rate_type' => 'weekly', 'amount_min' => '100000', 'currency' => 'EUR']]],
    ])]);

    expect(ImportedYacht::count())->toBe(1);
});

test('usage.display false is a ceiling no policy can raise', function () {
    acceptanceSync([acceptableItem('uuid-1', ['usage' => ['display' => false]])], policy: 'accept_all');

    expect(ListingCopy::count())->toBe(1)
        ->and(ImportedYacht::count())->toBe(0);
})->group('ID-10');

test('accept_all publishes incomplete listings too; review publishes nothing', function () {
    $partner = acceptanceSync([acceptableItem('uuid-1', ['media' => ['profile' => null]])], policy: 'accept_all');

    expect(ImportedYacht::count())->toBe(1);

    ImportedYacht::query()->delete();
    ListingCopy::query()->delete();
    $partner->update(['acceptance_policy' => AcceptancePolicy::Review->value, 'last_synced_at' => null]);

    app(SyncService::class)->sync($partner->refresh());

    expect(ListingCopy::count())->toBe(1)
        ->and(ImportedYacht::count())->toBe(0);
});

test('a trusted group policy covers its members', function () {
    $partner = acceptanceSync([acceptableItem('uuid-1')], policy: 'review');

    // The partner's own setting holds everything for review…
    expect(ImportedYacht::count())->toBe(0);

    // …until it joins a group whose policy is auto-publish: membership is
    // the grant, and the most permissive policy applies.
    $group = PartnerGroup::factory()->create(['acceptance_policy' => 'accept_complete']);
    $group->members()->attach($partner);
    $partner->update(['last_synced_at' => null]);

    app(SyncService::class)->sync($partner->refresh());

    $imported = ImportedYacht::query()->firstOrFail();

    expect($imported->auto_published_at)->not->toBeNull();
});

test('a group without a policy contributes nothing', function () {
    $partner = FederationPartner::factory()->verified()->create([
        'domain' => 'openyacht.partner.example',
        'acceptance_policy' => 'review',
    ]);
    PartnerGroup::factory()->create(['acceptance_policy' => null])->members()->attach($partner);

    Http::fake([
        'openyacht.partner.example/openyacht/v1/listings?*' => Http::response([
            'data' => [acceptableItem('uuid-1')],
            'meta' => ['generated_at' => '2026-08-21T12:00:00Z', 'protocol_version' => '1.0'],
        ]),
    ]);

    app(SyncService::class)->sync($partner);

    expect(ImportedYacht::count())->toBe(0)
        ->and($partner->effectiveAcceptancePolicy())->toBe(AcceptancePolicy::Review);
});

test('the most permissive of the partner and group policies wins', function () {
    $partner = FederationPartner::factory()->verified()->create([
        'domain' => 'openyacht.partner.example',
        'acceptance_policy' => 'accept_complete',
    ]);
    PartnerGroup::factory()->create(['acceptance_policy' => 'accept_all'])->members()->attach($partner);

    // An incomplete listing (no profile image) still publishes: the
    // group's accept_all outranks the partner's accept_complete.
    Http::fake([
        'openyacht.partner.example/openyacht/v1/listings?*' => Http::response([
            'data' => [acceptableItem('uuid-1', ['media' => ['profile' => null]])],
            'meta' => ['generated_at' => '2026-08-21T12:00:00Z', 'protocol_version' => '1.0'],
        ]),
    ]);

    app(SyncService::class)->sync($partner);

    expect($partner->effectiveAcceptancePolicy())->toBe(AcceptancePolicy::AcceptAll)
        ->and(ImportedYacht::count())->toBe(1);
});

test('a hard vessel match against an own listing flags the copy and blocks auto-publish', function () {
    SaleYacht::factory()->active()
        ->for(Vessel::factory()->state(['imo' => '9123456']))
        ->create();

    acceptanceSync([acceptableItem('uuid-1', ['vessel' => ['imo' => '9123456']])]);

    $copy = ListingCopy::query()->firstOrFail();

    // Retain both, flag for human review, never auto-resolve (ID-9) —
    // and the auto-publish path must consult the flag.
    expect($copy->identity_conflicts)->toHaveCount(1)
        ->and($copy->identity_conflicts[0]['matched_on'])->toBe('imo')
        ->and($copy->identity_conflicts[0]['with'])->toBe('own')
        ->and(ImportedYacht::count())->toBe(0);
})->group('ID-9');

test('a soft candidate match queues the same way, and a dismissal lets the next sync publish', function () {
    SaleYacht::factory()->active()
        ->for(Vessel::factory()->state([
            'builder_name' => 'Benetti', 'model_name' => 'Oasis 40M',
            'year_built' => 2021, 'loa_m' => 40.6,
        ]))
        ->create();

    $partner = acceptanceSync([acceptableItem('uuid-1')]);

    $copy = ListingCopy::query()->firstOrFail();

    // builder + model + year_built + loa_m within tolerance is a
    // candidate match requiring human confirmation, never automation.
    expect($copy->identity_conflicts[0]['matched_on'])->toBe('vessel_profile')
        ->and(ImportedYacht::count())->toBe(0);

    // The dismissal is the human confirmation; the unchanged conflict
    // set survives the next sync and the policy proceeds.
    $copy->update(['conflict_reviewed_at' => now()]);

    app(SyncService::class)->sync($partner->refresh());

    expect(ListingCopy::query()->firstOrFail()->conflict_reviewed_at)->not->toBeNull()
        ->and(ImportedYacht::count())->toBe(1);
})->group('ID-9');

test('copies from the same authority never conflict with each other', function () {
    acceptanceSync([
        acceptableItem('uuid-1', ['vessel' => ['hin' => 'XYZ12345D404']]),
        acceptableItem('uuid-2', ['vessel' => ['hin' => 'XYZ12345D404'], 'listing' => ['name' => 'OASIS RELISTED']]),
    ]);

    // Two listings from one authority are that authority's own affair —
    // ID-9 is about DIFFERENT authorities claiming the same vessel.
    expect(ListingCopy::query()->whereNotNull('identity_conflicts')->count())->toBe(0);
})->group('ID-9');

test('a hard match across two partners flags the later copy', function () {
    $first = FederationPartner::factory()->verified()->create(['domain' => 'openyacht.first.example']);
    ListingCopy::factory()->create([
        'federation_partner_id' => $first->id,
        'authority_domain' => 'openyacht.first.example',
        'payload' => acceptableItem('uuid-0', ['vessel' => ['imo' => '9123456']]),
    ]);

    acceptanceSync([acceptableItem('uuid-1', ['vessel' => ['imo' => '9123456']])]);

    $copy = ListingCopy::query()->where('authority_domain', 'openyacht.partner.example')->firstOrFail();

    expect($copy->identity_conflicts[0]['with'])->toBe('partner')
        ->and($copy->identity_conflicts[0]['matched_on'])->toBe('imo');
})->group('ID-9');

test('a changed conflict set invalidates an earlier review', function () {
    $partner = FederationPartner::factory()->verified()->create([
        'domain' => 'openyacht.partner.example',
        'acceptance_policy' => 'accept_complete',
    ]);

    Http::fake([
        'openyacht.partner.example/openyacht/v1/listings?*' => Http::sequence()
            ->push(['data' => [acceptableItem('uuid-1')], 'meta' => ['generated_at' => '2026-08-21T12:00:00Z', 'protocol_version' => '1.0']])
            ->push(['data' => [acceptableItem('uuid-1', ['vessel' => ['imo' => '9123456'], 'updated_at' => '2026-08-22T09:00:00Z'])], 'meta' => ['generated_at' => '2026-08-22T09:00:01Z', 'protocol_version' => '1.0']]),
    ]);

    app(SyncService::class)->sync($partner);

    $copy = ListingCopy::query()->firstOrFail();
    $copy->update(['conflict_reviewed_at' => now()]);

    // A hard identifier appears on the next poll: the old dismissal must
    // not cover a match the reviewer never saw.
    SaleYacht::factory()->active()
        ->for(Vessel::factory()->state(['imo' => '9123456']))
        ->create();

    app(SyncService::class)->sync($partner->refresh());

    $copy->refresh();

    expect($copy->identity_conflicts)->toHaveCount(1)
        ->and($copy->conflict_reviewed_at)->toBeNull();
})->group('ID-9');
