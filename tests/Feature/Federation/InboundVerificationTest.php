<?php

use App\Enums\Role;
use App\Enums\TrustLevel;
use App\Models\FederationPartner;
use App\Models\SaleYacht;
use App\Models\User;
use App\Notifications\PartnerFirstContact;
use App\Services\Federation\PartnerService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

const RECEIVER_HOST = 'this-node.example';
const RECEIVER_BASE = 'https://this-node.example';
const SENDER = 'openyacht.sender.example';

beforeEach(function () {
    config(['openyacht.domain' => RECEIVER_HOST]);
    $this->keypair = federationTestKeypair();
});

function senderWellKnown(array $keypair, string $uuid = '018f0000-0000-7000-8000-000000000001'): array
{
    return [
        'openyacht' => '1.0',
        'node' => ['uuid' => $uuid, 'name' => 'Sender'],
        'keys' => [[
            'key_id' => $keypair['key_id'],
            'algorithm' => 'ed25519',
            'public_key' => $keypair['public_key'],
            'created_at' => '2026-08-20T10:30:00Z',
        ]],
    ];
}

function senderGet(object $test, string $path, ?string $timestamp = null)
{
    return $test->get(RECEIVER_BASE.$path, federationSignedHeaders(
        SENDER,
        $test->keypair['key_id'],
        $test->keypair['secret_key'],
        'GET',
        $path,
        RECEIVER_HOST,
        timestamp: $timestamp,
    ));
}

test('first contact from an unknown domain creates a provisional partner and notifies subscribed users', function () {
    Notification::fake();
    $this->seed(RoleSeeder::class);
    $subscribed = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::SuperAdmin));
    $unsubscribed = User::factory()->create();

    Http::fake([
        SENDER.'/.well-known/openyacht' => Http::response(senderWellKnown($this->keypair)),
    ]);

    senderGet($this, '/openyacht/v1/listings')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'PARTNER_PROVISIONAL');

    $partner = FederationPartner::query()->where('domain', SENDER)->first();

    expect($partner)->not->toBeNull()
        ->and($partner->trust_level)->toBe(TrustLevel::Provisional)
        ->and($partner->publishedKeys())->toHaveKey($this->keypair['key_id']);

    // The unsolicited introduction is emailed to holders of the
    // federation.notifications permission — nobody else.
    Notification::assertSentTo($subscribed, PartnerFirstContact::class, fn (PartnerFirstContact $notification): bool => $notification->partner->is($partner));
    Notification::assertNotSentTo($unsubscribed, PartnerFirstContact::class);
})->group('FP-13');

test('a repeat contact from a known domain sends no first-contact notification', function () {
    Notification::fake();
    $this->seed(RoleSeeder::class);
    tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::SuperAdmin));

    FederationPartner::factory()->create([
        'domain' => SENDER,
        'keys_json' => senderWellKnown($this->keypair)['keys'],
    ]);

    senderGet($this, '/openyacht/v1/listings')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'PARTNER_PROVISIONAL');

    Notification::assertNothingSent();
})->group('FP-13');

test('an unknown domain with an unreachable well-known document is rejected', function () {
    Http::fake([
        SENDER.'/.well-known/openyacht' => Http::response(null, 500),
    ]);

    senderGet($this, '/openyacht/v1/listings')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'PARTNER_UNKNOWN');
})->group('FP-13');

test('a request claiming to come from this node itself is rejected without creating a partner', function () {
    Http::fake();

    $this->get(RECEIVER_BASE.'/openyacht/v1/listings', federationSignedHeaders(
        RECEIVER_HOST,
        $this->keypair['key_id'],
        $this->keypair['secret_key'],
        'GET',
        '/openyacht/v1/listings',
        RECEIVER_HOST,
    ))
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'SIGNATURE_INVALID');

    expect(FederationPartner::query()->where('domain', RECEIVER_HOST)->exists())->toBeFalse();
    Http::assertNothingSent();
});

test('a rotated key is picked up by the failure-triggered refetch', function () {
    $staleKeypair = federationTestKeypair();

    FederationPartner::factory()->verified()->create([
        'domain' => SENDER,
        'node_uuid' => '018f0000-0000-7000-8000-000000000001',
        'keys_json' => senderWellKnown($staleKeypair)['keys'],
    ]);

    Http::fake([
        SENDER.'/.well-known/openyacht' => Http::response(senderWellKnown($this->keypair)),
    ]);

    SaleYacht::factory()->active()->create();

    // Signed with the new key the receiver has not yet cached: the first
    // verification fails, the refetch updates the cache, the retry
    // succeeds — rotation needs no coordination.
    senderGet($this, '/openyacht/v1/listings')->assertOk();

    Http::assertSentCount(1);
})->group('FP-10');

test('verification failure without a matching fresh key is rejected after one refetch', function () {
    $staleKeypair = federationTestKeypair();

    FederationPartner::factory()->verified()->create([
        'domain' => SENDER,
        'node_uuid' => '018f0000-0000-7000-8000-000000000001',
        'keys_json' => senderWellKnown($staleKeypair)['keys'],
    ]);

    Http::fake([
        SENDER.'/.well-known/openyacht' => Http::response(senderWellKnown($staleKeypair)),
    ]);

    senderGet($this, '/openyacht/v1/listings')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'SIGNATURE_INVALID');
})->group('FP-10');

test('a node UUID change on refetch downgrades the partner and rejects the request', function () {
    $staleKeypair = federationTestKeypair();

    $partner = FederationPartner::factory()->verified()->create([
        'domain' => SENDER,
        'node_uuid' => '018f0000-0000-7000-8000-000000000001',
        'keys_json' => senderWellKnown($staleKeypair)['keys'],
    ]);

    Http::fake([
        SENDER.'/.well-known/openyacht' => Http::response(
            senderWellKnown($this->keypair, uuid: '018f9999-9999-7999-8999-999999999999'),
        ),
    ]);

    senderGet($this, '/openyacht/v1/listings')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'SIGNATURE_INVALID');

    expect($partner->fresh()->trust_level)->toBe(TrustLevel::Provisional);
})->group('FP-11');

test('a pinned partner may only present the pinned key', function () {
    FederationPartner::factory()->verified()->create([
        'domain' => SENDER,
        'keys_json' => senderWellKnown($this->keypair)['keys'],
        'pinned_key_id' => 'fe06271acc7d35b9',
    ]);

    senderGet($this, '/openyacht/v1/listings')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'SIGNATURE_INVALID');
})->group('FP-12');

test('a pinned partner that rotates is accepted again after the administrator confirms by refreshing keys', function () {
    $pinnedKeypair = federationTestKeypair();

    $partner = FederationPartner::factory()->verified()->create([
        'domain' => SENDER,
        'node_uuid' => '018f0000-0000-7000-8000-000000000001',
        'keys_json' => senderWellKnown($pinnedKeypair)['keys'],
        'pinned_key_id' => $pinnedKeypair['key_id'],
    ]);

    // Routine rotation with overlap: the new signing key is served
    // first, the pinned old key stays published for the overlap window.
    Http::fake([
        SENDER.'/.well-known/openyacht' => Http::response([
            'openyacht' => '1.0',
            'node' => ['uuid' => '018f0000-0000-7000-8000-000000000001', 'name' => 'Sender'],
            'keys' => [
                [
                    'key_id' => $this->keypair['key_id'],
                    'algorithm' => 'ed25519',
                    'public_key' => $this->keypair['public_key'],
                    'created_at' => '2026-08-23T10:30:00Z',
                ],
                [
                    'key_id' => $pinnedKeypair['key_id'],
                    'algorithm' => 'ed25519',
                    'public_key' => $pinnedKeypair['public_key'],
                    'created_at' => '2026-08-20T10:30:00Z',
                ],
            ],
        ]),
    ]);

    SaleYacht::factory()->active()->create();

    // Rejected while the pin still points at the old key (FP-12)…
    senderGet($this, '/openyacht/v1/listings')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'SIGNATURE_INVALID');

    // …until the explicit admin key refresh performs the confirmation
    // and moves the pin to the rotated key.
    app(PartnerService::class)->refreshKeys($partner, pinConfirmedBy: User::factory()->create());

    senderGet($this, '/openyacht/v1/listings')->assertOk();
})->group('FP-12');

test('timestamps outside the window are rejected as out of range', function () {
    FederationPartner::factory()->verified()->create([
        'domain' => SENDER,
        'keys_json' => senderWellKnown($this->keypair)['keys'],
    ]);

    senderGet($this, '/openyacht/v1/listings', timestamp: now()->subMinutes(10)->utc()->format('Y-m-d\TH:i:s\Z'))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'TIMESTAMP_OUT_OF_RANGE');
})->group('FP-8');

test('a provisional partner can request partnership', function () {
    FederationPartner::factory()->create([
        'domain' => SENDER,
        'keys_json' => senderWellKnown($this->keypair)['keys'],
    ]);

    $path = '/openyacht/v1/partners/request';
    $body = '{"message":"Requesting partnership.","contact_email":"broker@sender.example"}';

    $response = $this->call(
        'POST',
        RECEIVER_BASE.$path,
        server: collect(federationSignedHeaders(SENDER, $this->keypair['key_id'], $this->keypair['secret_key'], 'POST', $path, RECEIVER_HOST, $body))
            ->mapWithKeys(fn (string $value, string $name): array => ['HTTP_'.strtoupper(str_replace('-', '_', $name)) => $value])
            ->put('CONTENT_TYPE', 'application/json')
            ->all(),
        content: $body,
    );

    $response->assertStatus(202)->assertJsonPath('status', 'received');

    expect(Activity::query()->where('event', 'partner_request_received')->exists())->toBeTrue();
})->group('FP-13');
