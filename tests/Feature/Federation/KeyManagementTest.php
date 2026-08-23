<?php

use App\Enums\KeyStatus;
use App\Models\FederationKey;
use App\Services\Federation\KeyManager;
use Illuminate\Support\Facades\DB;

test('generated keys derive the key ID from the SHA-256 of the raw public key', function () {
    $key = app(KeyManager::class)->generate();

    expect($key->key_id)
        ->toBe(substr(hash('sha256', $key->rawPublicKey()), 0, 16))
        ->toHaveLength(16);
})->group('FP-3');

test('the private key is encrypted at rest', function () {
    $key = app(KeyManager::class)->generate();

    $storedPrivateKey = DB::table('federation_keys')
        ->where('id', $key->id)
        ->value('private_key');

    expect($storedPrivateKey)->not->toBe($key->private_key)
        ->and($storedPrivateKey)->not->toContain($key->private_key)
        ->and($key->fresh()->private_key)->toBe($key->private_key);
})->group('FP-4');

test('the private key is hidden from serialization', function () {
    $key = app(KeyManager::class)->generate();

    expect($key->toArray())->not->toHaveKey('private_key');
})->group('FP-4');

test('routine rotation keeps the old key published as retiring', function () {
    $keys = app(KeyManager::class);
    $original = $keys->generate();

    $replacement = $keys->rotate();

    expect($original->fresh()->status)->toBe(KeyStatus::Retiring)
        ->and($replacement->status)->toBe(KeyStatus::Active)
        ->and($keys->activeKey()->is($replacement))->toBeTrue()
        ->and($keys->publishedKeys()->pluck('key_id'))
        ->toContain($original->key_id, $replacement->key_id);
});

test('emergency rotation revokes every existing key immediately', function () {
    $keys = app(KeyManager::class);
    $original = $keys->generate();
    $retiring = FederationKey::factory()->retiring()->create();

    $replacement = $keys->rotateEmergency();

    expect($original->fresh()->status)->toBe(KeyStatus::Revoked)
        ->and($retiring->fresh()->status)->toBe(KeyStatus::Revoked)
        ->and($keys->publishedKeys()->pluck('key_id')->all())->toBe([$replacement->key_id]);
});

test('the rotate command performs routine rotation, retirement, and emergency rotation', function () {
    $keys = app(KeyManager::class);
    $original = $keys->generate();

    $this->artisan('openyacht:key:rotate')->assertSuccessful();

    expect($original->fresh()->status)->toBe(KeyStatus::Retiring)
        ->and($keys->publishedKeys())->toHaveCount(2);

    $this->artisan('openyacht:key:rotate --retire')->assertSuccessful();

    expect($original->fresh()->status)->toBe(KeyStatus::Revoked)
        ->and($keys->publishedKeys())->toHaveCount(1);

    $this->artisan('openyacht:key:rotate --emergency')->assertSuccessful();

    expect($keys->publishedKeys())->toHaveCount(1)
        ->and(FederationKey::query()->where('status', KeyStatus::Revoked)->count())->toBe(2);
});

test('the install command generates a first key and is idempotent', function () {
    config(['openyacht.domain' => 'openyacht.example.test', 'openyacht.node_uuid' => '018f3c2e-0000-7000-8000-000000000001']);

    $this->artisan('openyacht:install')->assertSuccessful();
    $this->artisan('openyacht:install')->assertSuccessful();

    expect(FederationKey::count())->toBe(1);
});
