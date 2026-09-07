<?php

use App\Enums\ImportTypes;
use App\Enums\Role;
use App\Enums\TrustLevel;
use App\Models\FederationKey;
use App\Models\FederationPartner;
use App\Models\ListingCopy;
use App\Models\User;
use App\Models\VisibilityEvent;
use App\Services\Federation\NodeDirectoryIndex;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['openyacht.domain' => 'openyacht.this-node.example']);
    FederationKey::factory()->create();
});

function federationActor(Role $role = Role::SuperAdmin): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
}

test('partner management requires the federation permission', function () {
    FederationPartner::factory()->create();

    $this->actingAs(federationActor(Role::Admin))
        ->get(route('partners.index'))
        ->assertForbidden();

    $this->actingAs(federationActor(Role::SuperAdmin))
        ->get(route('partners.index'))
        ->assertOk();
});

test('a partner can be added by domain through the UI', function () {
    Http::fake([
        'openyacht.partner.example/openyacht/v1/partners/request' => Http::response(['status' => 'received', 'trust_level' => 'provisional'], 202),
        'openyacht.partner.example/.well-known/openyacht' => Http::response([
            'openyacht' => '1.0',
            'node' => ['uuid' => '018f0000-0000-7000-8000-000000000001', 'name' => 'Partner'],
            'keys' => [['key_id' => '5e318f8cf9cbe249', 'public_key' => base64_encode(str_repeat('k', 32))]],
        ]),
    ]);

    $this->actingAs(federationActor())
        ->post(route('partners.store'), ['domain' => 'OpenYacht.Partner.Example'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $partner = FederationPartner::query()->where('domain', 'openyacht.partner.example')->first();

    expect($partner)->not->toBeNull()
        ->and($partner->request_sent_at)->not->toBeNull();
});

test('an unreachable domain surfaces as a validation error', function () {
    Http::fake([
        'unreachable.example/.well-known/openyacht' => Http::response(null, 500),
    ]);

    $this->actingAs(federationActor())
        ->post(route('partners.store'), ['domain' => 'unreachable.example'])
        ->assertSessionHasErrors('domain');

    expect(FederationPartner::count())->toBe(0);
});

test('a domain with a scheme or path is rejected', function () {
    $this->actingAs(federationActor())
        ->post(route('partners.store'), ['domain' => 'https://partner.example/path'])
        ->assertSessionHasErrors('domain');
});

test('a partner can be approved and blocked through the UI', function () {
    $partner = FederationPartner::factory()->create();
    $actor = federationActor();

    $this->actingAs($actor)
        ->post(route('partners.approve', $partner))
        ->assertRedirect();

    expect($partner->refresh()->trust_level)->toBe(TrustLevel::Verified)
        ->and($partner->approved_by_user_id)->toBe($actor->id);

    $this->actingAs($actor)
        ->post(route('partners.block', $partner))
        ->assertRedirect();

    expect($partner->refresh()->trust_level)->toBe(TrustLevel::Blocked);
});

test("the node's own identity domain cannot be added as a partner", function () {
    config(['openyacht.domain' => 'openyacht.this-node.example']);
    Http::fake();

    $this->actingAs(federationActor())
        ->from(route('partners.index'))
        ->post(route('partners.store'), ['domain' => 'OpenYacht.This-Node.Example'])
        ->assertRedirect(route('partners.index'))
        ->assertSessionHasErrors(['domain' => __('federation.domain_is_self')]);

    expect(FederationPartner::query()->count())->toBe(0);
    // Rejected before any well-known fetch.
    Http::assertNothingSent();
});

test('a partner nothing has been received from can be removed, taking its sharing rows with it', function () {
    $partner = FederationPartner::factory()->verified()->create();
    $partner->groups()->create(['name' => 'Offices']);
    DB::table('listing_audience_partners')->insert([
        'listing_uuid' => (string) Str::uuid7(),
        'federation_partner_id' => $partner->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    VisibilityEvent::query()->create([
        'listing_uuid' => (string) Str::uuid7(),
        'federation_partner_id' => $partner->id,
        'event' => 'visible',
        'occurred_at' => now(),
    ]);

    $this->actingAs(federationActor(Role::Admin))
        ->delete(route('partners.destroy', $partner))
        ->assertForbidden();

    $this->actingAs(federationActor())
        ->delete(route('partners.destroy', $partner))
        ->assertRedirect(route('partners.index'))
        ->assertSessionHasNoErrors();

    expect(FederationPartner::query()->whereKey($partner->id)->exists())->toBeFalse()
        ->and(DB::table('listing_audience_partners')->where('federation_partner_id', $partner->id)->exists())->toBeFalse()
        ->and(DB::table('partner_group_members')->where('federation_partner_id', $partner->id)->exists())->toBeFalse()
        ->and(VisibilityEvent::query()->where('federation_partner_id', $partner->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'partner_removed')->where('properties->domain', $partner->domain)->exists())->toBeTrue();
});

test('a partner with received copies cannot be removed, only blocked', function () {
    $copy = ListingCopy::factory()->create();
    $partner = $copy->partner;

    $this->actingAs(federationActor())
        ->get(route('partners.show', $partner))
        ->assertInertia(fn (Assert $page) => $page->where('partner.is_removable', false));

    $this->actingAs(federationActor())
        ->from(route('partners.show', $partner))
        ->delete(route('partners.destroy', $partner))
        ->assertRedirect(route('partners.show', $partner))
        ->assertSessionHasErrors('partner');

    expect(FederationPartner::query()->whereKey($partner->id)->exists())->toBeTrue()
        ->and(ListingCopy::query()->whereKey($copy->id)->exists())->toBeTrue();
});

test('the synced listings page requires a listings permission', function () {
    $this->actingAs(federationActor(Role::Viewer))
        ->get(route('synced-listings.index'))
        ->assertForbidden();
});

test('a directory entry is added as a partner through the same TOFU path', function () {
    Storage::fake('local');
    Http::fake([
        NodeDirectoryIndex::CANONICAL_URL => Http::response([
            'registry' => 'openyacht-nodes',
            'version' => '2026.08.0',
            'nodes' => [[
                'domain' => 'openyacht.partner.example',
                'name' => 'Partner Brokerage',
                'website' => 'https://partner.example',
                'country' => 'US',
                'listed_at' => '2026-08-23',
            ]],
        ]),
        'openyacht.partner.example/openyacht/v1/partners/request' => Http::response(['status' => 'received', 'trust_level' => 'provisional'], 202),
        'openyacht.partner.example/.well-known/openyacht' => Http::response([
            'openyacht' => '1.0',
            'node' => ['uuid' => '018f0000-0000-7000-8000-000000000001', 'name' => 'Partner'],
            'keys' => [['key_id' => '5e318f8cf9cbe249', 'public_key' => base64_encode(str_repeat('k', 32))]],
        ]),
    ]);

    app(NodeDirectoryIndex::class)->refresh();

    $actor = federationActor();

    $this->actingAs($actor)
        ->post(route('node-directory.add-partner'), ['domain' => 'openyacht.partner.example'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $partner = FederationPartner::query()->where('domain', 'openyacht.partner.example')->first();

    expect($partner)->not->toBeNull()
        ->and($partner->trust_level)->toBe(TrustLevel::Provisional)
        ->and($partner->publishedKeys())->toHaveKey('5e318f8cf9cbe249')
        ->and($partner->request_sent_at)->not->toBeNull();

    // A directory entry conveys existence only; the introduction is what
    // makes the partnership visible on their side.
    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://openyacht.partner.example/openyacht/v1/partners/request'
        && $request['contact_email'] === $actor->email);
})->group('FP-16', 'FP-13');

test('a domain outside the directory cannot be added through the directory path', function () {
    Storage::fake('local');

    $this->actingAs(federationActor())
        ->post(route('node-directory.add-partner'), ['domain' => 'unlisted.example'])
        ->assertSessionHasErrors('domain');

    expect(FederationPartner::count())->toBe(0);
})->group('FP-16');

test('directory actions require the federation permission', function () {
    $this->actingAs(federationActor(Role::Admin))
        ->post(route('node-directory.refresh'))
        ->assertForbidden();

    $this->actingAs(federationActor(Role::Admin))
        ->post(route('node-directory.add-partner'), ['domain' => 'openyacht.partner.example'])
        ->assertForbidden();

    $this->actingAs(federationActor(Role::Admin))
        ->get(route('node-directory.index'))
        ->assertForbidden();
});

test('the node directory page shows findability and precomputed listing requests', function () {
    Storage::fake('local');

    Http::fake([
        NodeDirectoryIndex::CANONICAL_URL => Http::response([
            'registry' => 'openyacht-nodes',
            'version' => '2026.08.0',
            'nodes' => [[
                'domain' => 'openyacht.this-node.example',
                'name' => 'This Node',
                'website' => 'https://this-node.example',
                'country' => 'US',
                'listed_at' => '2026-08-23',
            ]],
        ]),
    ]);

    app(NodeDirectoryIndex::class)->refresh();

    $this->actingAs(federationActor())
        ->get(route('node-directory.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('federation/directory/Index')
            ->where('domain', 'openyacht.this-node.example')
            ->where('listed', true)
            ->has('listingRequests.list.token')
            ->has('listingRequests.delist.signature')
            ->has('listingRequests.amend')
            ->where('directory.0.status', 'self'));
})->group('FP-16');

test('import types are managed with the federation permission and hide the excluded review queue', function () {
    $partner = FederationPartner::factory()->verified()->create();
    $saleCopy = ListingCopy::factory()->create([
        'federation_partner_id' => $partner->id,
        'type' => 'sale',
    ]);
    $charterCopy = ListingCopy::factory()->create([
        'federation_partner_id' => $partner->id,
        'type' => 'charter',
    ]);

    $this->actingAs(federationActor(Role::Admin))
        ->put(route('partners.import-types.update', $partner), ['import_types' => 'sale'])
        ->assertForbidden();

    $this->actingAs(federationActor(Role::SuperAdmin))
        ->put(route('partners.import-types.update', $partner), ['import_types' => 'sale'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($partner->refresh()->import_types)->toBe(ImportTypes::Sale);

    // The charter review queue no longer surfaces this partner's copies;
    // the sale queue still does.
    $listingIds = fn (string $routeName): array => collect(
        $this->actingAs(federationActor(Role::SuperAdmin))
            ->get(route($routeName))
            ->assertOk()
            ->original->getData()['page']['props']['copies']['data'],
    )->pluck('id')->all();

    expect($listingIds('synced-listings.index'))->toBe([$saleCopy->id])
        ->and($listingIds('synced-charter-listings.index'))->not->toContain($charterCopy->id);
});
