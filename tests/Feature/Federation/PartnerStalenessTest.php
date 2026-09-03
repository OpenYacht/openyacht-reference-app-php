<?php

use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\FederationPartner;
use App\Models\ImportedYacht;
use App\Models\ListingCopy;
use App\Models\SaleYacht;
use App\Models\User;
use Database\Seeders\RoleSeeder;

/**
 * The two halves of the staleness rule (FP-15): a partner unreachable
 * beyond the flag threshold marks its copies stale everywhere, and one
 * unreachable beyond the hide threshold has them withheld from public
 * output altogether.
 *
 * The second half is the only thing that ever drops a vanished
 * authority's listings — it stops answering, so it can never send the
 * tombstone that would normally remove them.
 *
 * // federation-protocol.md §Health and Failure Handling
 */
beforeEach(function () {
    config(['openyacht.domain' => 'this-node.example']);
    $this->apiKey = ApiKey::generate('Staleness key', ['yachts:read'])['plaintext'];
});

function importedFrom(FederationPartner $partner): ImportedYacht
{
    return ImportedYacht::factory()
        ->for(ListingCopy::factory()->for($partner, 'partner'), 'copy')
        ->create();
}

test('copies from a partner unreachable past the hide threshold are withheld from the data API', function () {
    $vanished = importedFrom(FederationPartner::factory()->verified()->unreachableSince(31)->create());
    $reachable = importedFrom(FederationPartner::factory()->verified()->unreachableSince(1)->create());

    $keys = collect(
        $this->getJson('/api/v1/yachts?source=imported', ['X-API-Key' => $this->apiKey])
            ->assertOk()
            ->json('data')
    )->pluck('x_key');

    expect($keys)->toContain("imported-{$reachable->id}")
        ->and($keys)->not->toContain("imported-{$vanished->id}");
})->group('FP-15');

test('a stale partner still has its copies served, flagged stale', function () {
    $stale = importedFrom(FederationPartner::factory()->verified()->unreachableSince(8)->create());

    $item = collect(
        $this->getJson('/api/v1/yachts?source=imported', ['X-API-Key' => $this->apiKey])
            ->assertOk()
            ->json('data')
    )->firstWhere('x_key', "imported-{$stale->id}");

    expect($item)->not->toBeNull()
        ->and($item['x_provenance']['is_stale'])->toBeTrue();
})->group('FP-15');

test('a withheld copy is not dereferenceable by key either', function () {
    $vanished = importedFrom(FederationPartner::factory()->verified()->unreachableSince(31)->create());

    $this->getJson("/api/v1/yachts/imported-{$vanished->id}", ['X-API-Key' => $this->apiKey])
        ->assertNotFound();
})->group('FP-15');

test('withholding is scoped to the unreachable partner, never the whole feed', function () {
    $own = SaleYacht::factory()->active()->create();
    importedFrom(FederationPartner::factory()->verified()->unreachableSince(31)->create());

    $keys = collect(
        $this->getJson('/api/v1/yachts', ['X-API-Key' => $this->apiKey])
            ->assertOk()
            ->json('data')
    )->pluck('x_key');

    expect($keys)->toContain($own->uuid);
})->group('FP-15');

test('withheld copies stay visible to operators, marked rather than deleted', function () {
    $this->seed(RoleSeeder::class);
    $vanished = importedFrom(FederationPartner::factory()->verified()->unreachableSince(31)->create());
    $operator = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Editor));

    $this->actingAs($operator)
        ->get(route('imported-yachts.index'))
        ->assertOk()
        ->assertSee($vanished->name);

    expect(ImportedYacht::query()->whereKey($vanished->id)->exists())->toBeTrue();
})->group('FP-15');

test('a partner that has never synced successfully ages from when it was added', function () {
    $neverSynced = FederationPartner::factory()->verified()->create([
        'last_ok_at' => null,
        'created_at' => now()->subDays(31),
    ]);
    $justAdded = FederationPartner::factory()->verified()->create(['last_ok_at' => null]);

    expect($neverSynced->isStale())->toBeTrue()
        ->and($neverSynced->isHidden())->toBeTrue()
        ->and($justAdded->isStale())->toBeFalse()
        ->and($justAdded->isHidden())->toBeFalse();
})->group('FP-15');

test('the hide threshold is configurable and the SQL scope mirrors the predicate', function () {
    config(['openyacht.staleness.hide_after_days' => 90]);

    $partner = FederationPartner::factory()->verified()->unreachableSince(31)->create();

    expect($partner->isHidden())->toBeFalse()
        ->and(FederationPartner::query()->publiclyDisplayable()->whereKey($partner->id)->exists())->toBeTrue();

    config(['openyacht.staleness.hide_after_days' => 30]);

    expect($partner->isHidden())->toBeTrue()
        ->and(FederationPartner::query()->publiclyDisplayable()->whereKey($partner->id)->exists())->toBeFalse();
})->group('FP-15');
