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
        ->assertJsonPath('endpoints.subscriptions', '/openyacht/v1/subscriptions')
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

/**
 * .env.example ships OPENYACHT_NODE_NAME= and OPENYACHT_WEBSITE= blank, and
 * env() returns '' rather than null for a key that is present but empty. A
 * node that never filled them in must still publish a usable name and a
 * website that is a URI or null — never the empty string, which
 * well-known.schema.json does not allow for website.
 */
test('blank identity variables fall back instead of publishing empty strings', function () {
    $restore = [
        'OPENYACHT_NODE_NAME' => $_SERVER['OPENYACHT_NODE_NAME'] ?? null,
        'OPENYACHT_WEBSITE' => $_SERVER['OPENYACHT_WEBSITE'] ?? null,
        'APP_NAME' => $_SERVER['APP_NAME'] ?? null,
    ];

    $_SERVER['OPENYACHT_NODE_NAME'] = '';
    $_SERVER['OPENYACHT_WEBSITE'] = '';
    $_SERVER['APP_NAME'] = 'Harbourside Brokerage';

    try {
        $config = require config_path('openyacht.php');
    } finally {
        foreach ($restore as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
    }

    expect($config['node_name'])->toBe('Harbourside Brokerage')
        ->and($config['website'])->toBeNull();

    config([
        'openyacht.domain' => 'openyacht.example.test',
        'openyacht.node_name' => $config['node_name'],
        'openyacht.website' => $config['website'],
    ]);

    $node = $this->get('https://openyacht.example.test/.well-known/openyacht')->json('node');

    expect($node['name'])->toBe('Harbourside Brokerage')
        ->and($node['website'])->toBeNull();
})->group('FP-2');
