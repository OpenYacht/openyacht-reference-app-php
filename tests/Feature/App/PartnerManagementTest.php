<?php

use App\Enums\Role;
use App\Enums\TrustLevel;
use App\Models\FederationPartner;
use App\Models\User;
use App\Services\Federation\KeyManager;
use App\Services\Federation\NodeDirectoryIndex;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
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

    expect(FederationPartner::query()->where('domain', 'openyacht.partner.example')->exists())->toBeTrue();
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
        'openyacht.partner.example/.well-known/openyacht' => Http::response([
            'openyacht' => '1.0',
            'node' => ['uuid' => '018f0000-0000-7000-8000-000000000001', 'name' => 'Partner'],
            'keys' => [['key_id' => '5e318f8cf9cbe249', 'public_key' => base64_encode(str_repeat('k', 32))]],
        ]),
    ]);

    app(NodeDirectoryIndex::class)->refresh();

    $this->actingAs(federationActor())
        ->post(route('node-directory.add-partner'), ['domain' => 'openyacht.partner.example'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $partner = FederationPartner::query()->where('domain', 'openyacht.partner.example')->first();

    expect($partner)->not->toBeNull()
        ->and($partner->trust_level)->toBe(TrustLevel::Provisional)
        ->and($partner->publishedKeys())->toHaveKey('5e318f8cf9cbe249');
})->group('FP-16');

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
    config(['openyacht.domain' => 'openyacht.this-node.example']);
    app(KeyManager::class)->generate();

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
