<?php

use App\Enums\Role;
use App\Enums\TrustLevel;
use App\Models\FederationPartner;
use App\Models\User;
use App\Notifications\PartnerFirstContact;
use App\Notifications\PartnerNodeUuidChanged;
use App\Notifications\PartnershipRequested;
use App\Services\Federation\InvalidWellKnownDocument;
use App\Services\Federation\PartnerService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

function wellKnownDocument(array $overrides = []): array
{
    return array_replace_recursive([
        'openyacht' => '1.0',
        'protocol_versions' => ['1.0'],
        'node' => [
            'uuid' => '018f0000-0000-7000-8000-000000000001',
            'name' => 'Partner Brokerage',
            'software' => 'openyacht-reference/0.1',
            'website' => 'https://partner.example',
        ],
        'keys' => [
            [
                'key_id' => '5e318f8cf9cbe249',
                'algorithm' => 'ed25519',
                'public_key' => base64_encode(str_repeat('k', 32)),
                'created_at' => '2026-08-20T10:30:00Z',
            ],
        ],
        'endpoints' => ['listings' => '/openyacht/v1/listings'],
        'generated_at' => '2026-08-20T10:30:00Z',
    ], $overrides);
}

test('adding a partner fetches the well-known document over https and stores it as provisional', function () {
    Http::fake([
        'openyacht.partner.example/.well-known/openyacht' => Http::response(wellKnownDocument()),
    ]);

    $partner = app(PartnerService::class)->add('openyacht.partner.example');

    Http::assertSent(fn ($request) => Str::startsWith($request->url(), 'https://'));

    expect($partner->trust_level)->toBe(TrustLevel::Provisional)
        ->and($partner->node_uuid)->toBe('018f0000-0000-7000-8000-000000000001')
        ->and($partner->node_name)->toBe('Partner Brokerage')
        ->and($partner->publishedKeys())->toHaveKey('5e318f8cf9cbe249')
        ->and($partner->keys_fetched_at)->not->toBeNull();
})->group('FP-2', 'FP-13');

test('a well-known document missing required fields is rejected', function () {
    Http::fake([
        'openyacht.partner.example/.well-known/openyacht' => Http::response(['openyacht' => '1.0']),
    ]);

    app(PartnerService::class)->add('openyacht.partner.example');
})->throws(InvalidWellKnownDocument::class);

test('an unreachable well-known endpoint is rejected', function () {
    Http::fake([
        'openyacht.partner.example/.well-known/openyacht' => Http::response(null, 500),
    ]);

    app(PartnerService::class)->add('openyacht.partner.example');
})->throws(InvalidWellKnownDocument::class);

test('refreshing keys updates the cache when the node UUID is unchanged', function () {
    $partner = FederationPartner::factory()->verified()->create([
        'domain' => 'openyacht.partner.example',
        'node_uuid' => '018f0000-0000-7000-8000-000000000001',
    ]);

    Http::fake([
        'openyacht.partner.example/.well-known/openyacht' => Http::response(wellKnownDocument([
            'node' => ['name' => 'Renamed Brokerage'],
            'keys' => [
                [
                    'key_id' => 'fe06271acc7d35b9',
                    'algorithm' => 'ed25519',
                    'public_key' => base64_encode(str_repeat('n', 32)),
                    'created_at' => '2026-08-21T10:30:00Z',
                ],
            ],
        ])),
    ]);

    $partner = app(PartnerService::class)->refreshKeys($partner);

    expect($partner->trust_level)->toBe(TrustLevel::Verified)
        ->and($partner->node_name)->toBe('Renamed Brokerage')
        ->and($partner->publishedKeys())->toHaveKey('fe06271acc7d35b9');
});

test('a changed node UUID downgrades the partner to provisional and notifies administrators', function () {
    Notification::fake();
    $this->seed(RoleSeeder::class);

    $approver = User::factory()->create();
    $subscribed = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::SuperAdmin));
    $partner = FederationPartner::factory()->verified()->create([
        'domain' => 'openyacht.partner.example',
        'node_uuid' => '018f0000-0000-7000-8000-000000000001',
        'approved_by_user_id' => $approver->id,
    ]);

    Http::fake([
        'openyacht.partner.example/.well-known/openyacht' => Http::response(wellKnownDocument([
            'node' => ['uuid' => '018f9999-9999-7999-8999-999999999999'],
        ])),
    ]);

    $partner = app(PartnerService::class)->refreshKeys($partner);

    expect($partner->trust_level)->toBe(TrustLevel::Provisional)
        ->and($partner->node_uuid)->toBe('018f9999-9999-7999-8999-999999999999')
        ->and($partner->approved_by_user_id)->toBeNull();

    expect(Activity::query()->where('event', 'partner_uuid_changed')->exists())->toBeTrue();

    Notification::assertSentTo($subscribed, PartnerNodeUuidChanged::class, fn (PartnerNodeUuidChanged $notification): bool => $notification->partner->is($partner));
    Notification::assertNotSentTo($approver, PartnerNodeUuidChanged::class);
})->group('FP-11');

test('an operator-initiated add sends no first-contact notification', function () {
    Notification::fake();
    $this->seed(RoleSeeder::class);
    tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::SuperAdmin));

    Http::fake([
        'openyacht.partner.example/.well-known/openyacht' => Http::response(wellKnownDocument()),
    ]);

    app(PartnerService::class)->add('openyacht.partner.example');

    // The operator just did this themselves; only the middleware's
    // unsolicited inbound introduction is emailed.
    Notification::assertNotSentTo(User::all(), PartnerFirstContact::class);
})->group('FP-13');

test('an administrator key refresh moves the pin to the rotated current signing key', function () {
    $admin = User::factory()->create();
    $partner = FederationPartner::factory()->verified()->create([
        'domain' => 'openyacht.partner.example',
        'node_uuid' => '018f0000-0000-7000-8000-000000000001',
        'pinned_key_id' => '5e318f8cf9cbe249',
    ]);

    Http::fake([
        'openyacht.partner.example/.well-known/openyacht' => Http::response(wellKnownDocument([
            'keys' => [
                [
                    'key_id' => 'fe06271acc7d35b9',
                    'algorithm' => 'ed25519',
                    'public_key' => base64_encode(str_repeat('n', 32)),
                    'created_at' => '2026-08-23T10:30:00Z',
                ],
                [
                    'key_id' => '5e318f8cf9cbe249',
                    'algorithm' => 'ed25519',
                    'public_key' => base64_encode(str_repeat('k', 32)),
                    'created_at' => '2026-08-20T10:30:00Z',
                ],
            ],
        ])),
    ]);

    $partner = app(PartnerService::class)->refreshKeys($partner, pinConfirmedBy: $admin);

    expect($partner->pinned_key_id)->toBe('fe06271acc7d35b9');

    expect(Activity::query()->where('event', 'partner_key_repinned')->exists())->toBeTrue();
})->group('FP-12');

test('an automatic key refresh never moves the pin', function () {
    $partner = FederationPartner::factory()->verified()->create([
        'domain' => 'openyacht.partner.example',
        'node_uuid' => '018f0000-0000-7000-8000-000000000001',
        'pinned_key_id' => '5e318f8cf9cbe249',
    ]);

    Http::fake([
        'openyacht.partner.example/.well-known/openyacht' => Http::response(wellKnownDocument([
            'keys' => [
                [
                    'key_id' => 'fe06271acc7d35b9',
                    'algorithm' => 'ed25519',
                    'public_key' => base64_encode(str_repeat('n', 32)),
                    'created_at' => '2026-08-23T10:30:00Z',
                ],
            ],
        ])),
    ]);

    $partner = app(PartnerService::class)->refreshKeys($partner);

    expect($partner->pinned_key_id)->toBe('5e318f8cf9cbe249')
        ->and($partner->publishedKeys())->toHaveKey('fe06271acc7d35b9');
})->group('FP-12');

test('a key refresh on a UUID-changed document leaves the pin untouched', function () {
    $admin = User::factory()->create();
    $partner = FederationPartner::factory()->verified()->create([
        'domain' => 'openyacht.partner.example',
        'node_uuid' => '018f0000-0000-7000-8000-000000000001',
        'pinned_key_id' => '5e318f8cf9cbe249',
    ]);

    Http::fake([
        'openyacht.partner.example/.well-known/openyacht' => Http::response(wellKnownDocument([
            'node' => ['uuid' => '018f9999-9999-7999-8999-999999999999'],
            'keys' => [
                [
                    'key_id' => 'fe06271acc7d35b9',
                    'algorithm' => 'ed25519',
                    'public_key' => base64_encode(str_repeat('n', 32)),
                    'created_at' => '2026-08-23T10:30:00Z',
                ],
            ],
        ])),
    ]);

    $partner = app(PartnerService::class)->refreshKeys($partner, pinConfirmedBy: $admin);

    expect($partner->trust_level)->toBe(TrustLevel::Provisional)
        ->and($partner->pinned_key_id)->toBe('5e318f8cf9cbe249');

    expect(Activity::query()->where('event', 'partner_key_repinned')->exists())->toBeFalse();
})->group('FP-11', 'FP-12');

test('the current signing key falls back to the newest created_at when a node orders differently', function () {
    $admin = User::factory()->create();
    $partner = FederationPartner::factory()->verified()->create([
        'domain' => 'openyacht.partner.example',
        'node_uuid' => '018f0000-0000-7000-8000-000000000001',
        'pinned_key_id' => '5e318f8cf9cbe249',
    ]);

    // Oldest-first ordering: the conventional first entry is the old
    // pinned key, but a newer created_at further down wins.
    Http::fake([
        'openyacht.partner.example/.well-known/openyacht' => Http::response(wellKnownDocument([
            'keys' => [
                [
                    'key_id' => '5e318f8cf9cbe249',
                    'algorithm' => 'ed25519',
                    'public_key' => base64_encode(str_repeat('k', 32)),
                    'created_at' => '2026-08-20T10:30:00Z',
                ],
                [
                    'key_id' => 'fe06271acc7d35b9',
                    'algorithm' => 'ed25519',
                    'public_key' => base64_encode(str_repeat('n', 32)),
                    'created_at' => '2026-08-23T10:30:00Z',
                ],
            ],
        ])),
    ]);

    $partner = app(PartnerService::class)->refreshKeys($partner, pinConfirmedBy: $admin);

    expect($partner->pinned_key_id)->toBe('fe06271acc7d35b9');
})->group('FP-12');

test('the notification mails render the partner domain and a review link', function () {
    $partner = FederationPartner::factory()->create(['domain' => 'openyacht.partner.example']);

    foreach ([new PartnerFirstContact($partner), new PartnerNodeUuidChanged($partner)] as $notification) {
        $mail = $notification->toMail(User::factory()->make());

        expect($mail->subject)->toContain('openyacht.partner.example')
            ->and($mail->actionUrl)->toBe(route('partners.show', $partner))
            ->and(implode(' ', $mail->introLines))->toContain('openyacht.partner.example');
    }
})->group('FP-11', 'FP-13');

test('the first-contact and partnership-requested mails carry the request message and contact when the sender gave them', function () {
    $partner = FederationPartner::factory()->create([
        'domain' => 'openyacht.partner.example',
        'request_message' => 'We list in Palm Beach and would like to share.',
        'request_contact_email' => 'broker@partner.example',
        'requested_at' => now(),
    ]);

    foreach ([new PartnerFirstContact($partner), new PartnershipRequested($partner)] as $notification) {
        $mail = $notification->toMail(User::factory()->make());
        $lines = implode(' ', $mail->introLines);

        expect($mail->subject)->toContain('openyacht.partner.example')
            ->and($mail->actionUrl)->toBe(route('partners.show', $partner))
            ->and($lines)->toContain('We list in Palm Beach and would like to share.')
            ->and($lines)->toContain('broker@partner.example');
    }

    $silent = FederationPartner::factory()->create();
    $silentLines = implode(' ', (new PartnerFirstContact($silent))->toMail(User::factory()->make())->introLines);

    expect($silentLines)->not->toContain('Their message')
        ->and($silentLines)->not->toContain('Contact:');
})->group('FP-13');

test('approving a partner records the approver and verified trust', function () {
    $partner = FederationPartner::factory()->create();
    $approver = User::factory()->create();

    $partner = app(PartnerService::class)->approve($partner, $approver);

    expect($partner->trust_level)->toBe(TrustLevel::Verified)
        ->and($partner->approved_by_user_id)->toBe($approver->id);
})->group('FP-13');

test('blocking a partner sets blocked trust', function () {
    $partner = FederationPartner::factory()->verified()->create();
    $blocker = User::factory()->create();

    $partner = app(PartnerService::class)->block($partner, $blocker);

    expect($partner->trust_level)->toBe(TrustLevel::Blocked);
})->group('FP-9');

test('a partner unreachable beyond seven days is stale, a reachable one is not', function () {
    $stale = FederationPartner::factory()->unreachableSince(8)->create();
    $fresh = FederationPartner::factory()->unreachableSince(6)->create();

    expect($stale->isStale())->toBeTrue()
        ->and($fresh->isStale())->toBeFalse();
})->group('FP-15');

test('first contact arms the key pin to the partner current signing key', function () {
    Http::fake([
        'openyacht.partner.example/.well-known/openyacht' => Http::response(wellKnownDocument()),
    ]);

    $partner = app(PartnerService::class)->add('openyacht.partner.example');

    // The pin must be armed at first use, otherwise the verifier's pin
    // check is dead code and a later silent key swap is trusted (FP-12).
    expect($partner->pinned_key_id)->toBe('5e318f8cf9cbe249')
        ->and($partner->pinned_key_id)->toBe($partner->currentSigningKeyId());
})->group('FP-12');

test('approving a partner that predates pin-arming establishes the pin', function () {
    $this->seed(RoleSeeder::class);
    $approver = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::SuperAdmin));

    // A partner row with no pin (as created before the arming fix).
    $partner = FederationPartner::factory()->create(['pinned_key_id' => null]);
    $expected = $partner->currentSigningKeyId();

    $partner = app(PartnerService::class)->approve($partner, $approver);

    expect($partner->pinned_key_id)->not->toBeNull()->toBe($expected);
})->group('FP-12');

test('a well-known key whose id does not match its public key is rejected', function () {
    Http::fake([
        'openyacht.partner.example/.well-known/openyacht' => Http::response(wellKnownDocument([
            'keys' => [
                [
                    // A real 32-byte key, but labelled with someone else's id.
                    'key_id' => 'fe06271acc7d35b9',
                    'algorithm' => 'ed25519',
                    'public_key' => base64_encode(str_repeat('k', 32)),
                    'created_at' => '2026-08-20T10:30:00Z',
                ],
            ],
        ])),
    ]);

    expect(fn () => app(PartnerService::class)->add('openyacht.partner.example'))
        ->toThrow(InvalidWellKnownDocument::class);
})->group('FP-3');

test('a well-known key with a malformed public key is rejected', function () {
    Http::fake([
        'openyacht.partner.example/.well-known/openyacht' => Http::response(wellKnownDocument([
            'keys' => [
                [
                    'key_id' => '5e318f8cf9cbe249',
                    'algorithm' => 'ed25519',
                    'public_key' => base64_encode(str_repeat('k', 20)), // too short for Ed25519
                    'created_at' => '2026-08-20T10:30:00Z',
                ],
            ],
        ])),
    ]);

    expect(fn () => app(PartnerService::class)->add('openyacht.partner.example'))
        ->toThrow(InvalidWellKnownDocument::class);
})->group('FP-3');
