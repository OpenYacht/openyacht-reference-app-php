<?php

use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\ImportedYacht;
use App\Models\SaleYacht;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['openyacht.domain' => 'this-node.example']);
});

function apiKeyWithScopes(array $scopes, int $rateLimit = 60, ?array $domains = null): array
{
    return ApiKey::generate('Test key', $scopes, $domains, $rateLimit);
}

test('the api requires a key', function () {
    $this->getJson('/api/v1/yachts')->assertUnauthorized();
});

test('an invalid or revoked key is rejected', function () {
    $generated = apiKeyWithScopes(['yachts:read']);
    $generated['key']->update(['is_active' => false]);

    $this->getJson('/api/v1/yachts', ['X-API-Key' => 'oy_not-a-real-key'])
        ->assertUnauthorized();

    $this->getJson('/api/v1/yachts', ['X-API-Key' => $generated['plaintext']])
        ->assertUnauthorized();
});

test('keys are stored hashed, never in plaintext', function () {
    $generated = apiKeyWithScopes(['yachts:read']);

    $row = DB::table('api_keys')->first();

    expect($row->key_hash)->toBe(hash('sha256', $generated['plaintext']))
        ->and(json_encode($row))->not->toContain($generated['plaintext']);
});

test('a key without the scope is refused', function () {
    $generated = apiKeyWithScopes([]);

    $this->getJson('/api/v1/yachts', ['X-API-Key' => $generated['plaintext']])
        ->assertForbidden();
});

test('the unified feed serves own and imported yachts in the wire shape', function () {
    $generated = apiKeyWithScopes(['yachts:read']);
    $own = SaleYacht::factory()->active()->create();
    SaleYacht::factory()->create();
    $imported = ImportedYacht::factory()->create();

    $response = $this->getJson('/api/v1/yachts', ['X-API-Key' => $generated['plaintext']])
        ->assertOk();

    $data = collect($response->json('data'));

    expect($data)->toHaveCount(2)
        ->and($response->json('meta.total'))->toBe(2);

    $ownItem = $data->firstWhere('x_source', 'own');
    $importedItem = $data->firstWhere('x_source', 'imported');

    expect($ownItem['id'])->toBe($own->canonicalUri())
        ->and($ownItem['x_key'])->toBe($own->uuid)
        ->and($ownItem['listing']['price']['amount'])->toBeString()
        ->and($ownItem['x_provenance'])->toBeNull();

    expect($importedItem['x_key'])->toBe("imported-{$imported->id}")
        ->and($importedItem['x_provenance']['canonical'])->not->toBeNull()
        ->and($importedItem['id'])->toBe($imported->copy->canonical_uri)
        ->and($importedItem)->toHaveKey('x_local_media');
});

test('source filters give own-only and imported-only views', function () {
    $generated = apiKeyWithScopes(['yachts:read']);
    SaleYacht::factory()->active()->create();
    ImportedYacht::factory()->create();

    $own = $this->getJson('/api/v1/yachts?source=own', ['X-API-Key' => $generated['plaintext']])->json('data');
    $imported = $this->getJson('/api/v1/yachts?source=imported', ['X-API-Key' => $generated['plaintext']])->json('data');

    expect(collect($own)->pluck('x_source')->unique()->all())->toBe(['own'])
        ->and(collect($imported)->pluck('x_source')->unique()->all())->toBe(['imported']);
});

test('single yachts dereference by uuid or imported key', function () {
    $generated = apiKeyWithScopes(['yachts:read']);
    $own = SaleYacht::factory()->active()->create();
    $imported = ImportedYacht::factory()->create();

    $this->getJson("/api/v1/yachts/{$own->uuid}", ['X-API-Key' => $generated['plaintext']])
        ->assertOk()
        ->assertJsonPath('data.x_source', 'own');

    $this->getJson("/api/v1/yachts/imported-{$imported->id}", ['X-API-Key' => $generated['plaintext']])
        ->assertOk()
        ->assertJsonPath('data.x_source', 'imported');

    $this->getJson('/api/v1/yachts/not-a-key', ['X-API-Key' => $generated['plaintext']])
        ->assertNotFound();
});

test('drafts never appear in the feed or dereference', function () {
    $generated = apiKeyWithScopes(['yachts:read']);
    $draft = SaleYacht::factory()->create();

    expect($this->getJson('/api/v1/yachts', ['X-API-Key' => $generated['plaintext']])->json('data'))
        ->toHaveCount(0);

    $this->getJson("/api/v1/yachts/{$draft->uuid}", ['X-API-Key' => $generated['plaintext']])
        ->assertNotFound();
});

test('the per-key rate limit returns 429 with Retry-After', function () {
    $generated = apiKeyWithScopes(['yachts:read'], rateLimit: 2);

    $this->getJson('/api/v1/yachts', ['X-API-Key' => $generated['plaintext']])->assertOk();
    $this->getJson('/api/v1/yachts', ['X-API-Key' => $generated['plaintext']])->assertOk();

    $this->getJson('/api/v1/yachts', ['X-API-Key' => $generated['plaintext']])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

test('domain-restricted keys refuse foreign browser origins', function () {
    $generated = apiKeyWithScopes(['yachts:read'], domains: ['example.com']);

    $this->getJson('/api/v1/yachts', [
        'X-API-Key' => $generated['plaintext'],
        'Origin' => 'https://evil.example.net',
    ])->assertForbidden();

    $this->getJson('/api/v1/yachts', [
        'X-API-Key' => $generated['plaintext'],
        'Origin' => 'https://example.com',
    ])->assertOk();
});

test('key management requires the settings permission and reveals the key once', function () {
    $this->seed(RoleSeeder::class);

    $admin = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Admin));
    $viewer = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Viewer));

    $this->actingAs($viewer)->get(route('api-keys.index'))->assertForbidden();

    $this->actingAs($admin)
        ->post(route('api-keys.store'), [
            'name' => 'Website',
            'scopes' => ['yachts:read'],
            'rate_limit' => 60,
        ])
        ->assertRedirect(route('api-keys.index'))
        ->assertSessionHas('new_api_key');

    expect(ApiKey::query()->count())->toBe(1)
        ->and(ApiKey::query()->first()->scopes)->toBe(['yachts:read']);
});
