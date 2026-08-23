<?php

use App\Models\FederationKey;

beforeEach(function () {
    config([
        'openyacht.domain' => 'openyacht.example.test',
        'openyacht.node_uuid' => '018f3c2e-0000-7000-8000-000000000001',
        'openyacht.node_name' => 'Example Node',
        'openyacht.website' => 'https://example.test',
    ]);
});

test('the well-known document contains versions, node identity, keys, and endpoints', function () {
    $key = FederationKey::factory()->create();

    $response = $this->get('https://openyacht.example.test/.well-known/openyacht');

    $response->assertOk()
        ->assertJson([
            'openyacht' => '1.0',
            'protocol_versions' => ['1.0'],
            'node' => [
                'uuid' => '018f3c2e-0000-7000-8000-000000000001',
                'name' => 'Example Node',
                'software' => 'openyacht-reference/0.1',
                'website' => 'https://example.test',
            ],
        ])
        ->assertJsonPath('keys.0.key_id', $key->key_id)
        ->assertJsonPath('keys.0.algorithm', 'ed25519')
        ->assertJsonPath('keys.0.public_key', $key->public_key)
        ->assertJsonPath('endpoints.listings', '/openyacht/v1/listings')
        ->assertJsonPath('endpoints.health', '/openyacht/v1/health')
        ->assertJsonPath('endpoints.capabilities', '/openyacht/v1/capabilities')
        ->assertJsonStructure(['generated_at']);
})->group('FP-1', 'FP-5');

test('active and retiring keys are published; revoked keys are not', function () {
    $active = FederationKey::factory()->create();
    $retiring = FederationKey::factory()->retiring()->create();
    $revoked = FederationKey::factory()->revoked()->create();

    $publishedKeyIds = collect(
        $this->get('https://openyacht.example.test/.well-known/openyacht')->json('keys')
    )->pluck('key_id');

    expect($publishedKeyIds)->toContain($active->key_id)
        ->toContain($retiring->key_id)
        ->not->toContain($revoked->key_id);
})->group('FP-1');

test('the well-known document never exposes private keys', function () {
    FederationKey::factory()->create();

    $response = $this->get('https://openyacht.example.test/.well-known/openyacht');

    expect($response->content())->not->toContain('private');
})->group('FP-4');

test('federation routes 404 on any host other than the identity domain', function () {
    FederationKey::factory()->create();

    $this->get('https://other-host.test/.well-known/openyacht')->assertNotFound();
    $this->get('https://other-host.test/openyacht/v1/health')->assertNotFound();
});

test('federation routes 404 when no identity domain is configured', function () {
    config(['openyacht.domain' => null]);

    $this->get('https://openyacht.example.test/.well-known/openyacht')->assertNotFound();
});
