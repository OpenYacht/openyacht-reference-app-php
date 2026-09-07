<?php

use App\Enums\TrustLevel;
use App\Jobs\SendChangeNotification;
use App\Models\FederationKey;
use App\Models\FederationPartner;
use App\Models\ListingCopy;
use App\Models\WebhookEndpoint;
use App\Services\Federation\SyncService;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

// The consumer half of push subscriptions (API-11): deliveries arrive at
// the inbox signed like any federation request, are deduplicated on
// (id, updated_at), and are applied exactly like a polled item — while
// the partner is still reconciled by polling. // api-design.md §Subscriptions

const INBOX_HOST = 'this-node.example';
const INBOX_BASE = 'https://this-node.example';
const AUTHORITY = 'openyacht.authority.example';

beforeEach(function () {
    config(['openyacht.domain' => INBOX_HOST, 'openyacht.change_notifications.cooldown_minutes' => 0]);

    FederationKey::factory()->create();

    $this->authorityKeypair = federationTestKeypair();
    $this->authority = FederationPartner::factory()->verified()->create([
        'domain' => AUTHORITY,
        'push_subscribed_at' => now(),
        'keys_json' => [[
            'key_id' => $this->authorityKeypair['key_id'],
            'algorithm' => 'ed25519',
            'public_key' => $this->authorityKeypair['public_key'],
            'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]],
    ]);
});

function pushedItem(string $uuid = '018f0000-0000-7000-8000-0000000000aa', array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'https://'.AUTHORITY."/openyacht/v1/listings/{$uuid}",
        'type' => 'sale',
        'status' => 'active',
        'updated_at' => '2026-09-01T10:00:00Z',
        'listing' => ['name' => 'OASIS'],
        'usage' => ['display' => true, 'attribution_required' => true, 'attribution_text' => 'Courtesy of Partner', 'expires_with_listing' => true],
    ], $overrides);
}

function pushedTombstone(string $uuid = '018f0000-0000-7000-8000-0000000000aa', string $updatedAt = '2026-09-02T09:00:00Z'): array
{
    return [
        'id' => 'https://'.AUTHORITY."/openyacht/v1/listings/{$uuid}",
        'tombstone' => true,
        'status' => 'withdrawn',
        'updated_at' => $updatedAt,
    ];
}

/**
 * A delivery signed by the authority, over the exact bytes postJson()
 * sends (json_encode with default flags).
 */
function deliver(object $test, array $item)
{
    return $test->postJson(INBOX_BASE.'/openyacht/v1/inbox', $item, federationSignedHeaders(
        AUTHORITY,
        $test->authorityKeypair['key_id'],
        $test->authorityKeypair['secret_key'],
        'POST',
        '/openyacht/v1/inbox',
        INBOX_HOST,
        json_encode($item),
    ));
}

test('the inbox requires a signature and a verified partner', function () {
    $this->postJson(INBOX_BASE.'/openyacht/v1/inbox', pushedItem())
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'SIGNATURE_INVALID');

    $this->authority->update(['trust_level' => TrustLevel::Provisional]);

    deliver($this, pushedItem())
        ->assertForbidden()
        ->assertJsonPath('error.code', 'PARTNER_PROVISIONAL');

    expect(ListingCopy::count())->toBe(0);
})->group('API-11', 'FP-6', 'FP-13');

test('a push from a partner this node never subscribed to is not found', function () {
    $this->authority->update(['push_subscribed_at' => null]);

    deliver($this, pushedItem())
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND');

    expect(ListingCopy::count())->toBe(0);
})->group('API-11');

test('a malformed delivery is a validation error', function (array $body) {
    deliver($this, $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
})->with([
    'empty object' => [[]],
    'no updated_at' => [['id' => 'https://'.AUTHORITY.'/openyacht/v1/listings/x']],
    'a page instead of an item' => [['data' => [], 'meta' => []]],
])->group('API-11', 'API-9');

test('a delivered listing is stored like a polled one and notifies the change webhooks like any applied batch', function () {
    WebhookEndpoint::factory()->create();
    Queue::fake();

    deliver($this, pushedItem())
        ->assertOk()
        ->assertJson(['status' => 'received', 'outcome' => 'created']);

    $copy = ListingCopy::query()->sole();

    expect($copy->federation_partner_id)->toBe($this->authority->id)
        ->and($copy->authority_domain)->toBe(AUTHORITY)
        ->and($copy->name)->toBe('OASIS')
        ->and($copy->listing_updated_at->format('Y-m-d\TH:i:s\Z'))->toBe('2026-09-01T10:00:00Z')
        ->and($copy->provenance()['signature_verified'])->toBeTrue();

    Queue::assertPushed(SendChangeNotification::class, fn (SendChangeNotification $job) => str_starts_with($job->reason, 'push:'.AUTHORITY.' created '));
})->group('API-11', 'ID-3');

test('a replayed delivery is acknowledged and applied only once', function () {
    WebhookEndpoint::factory()->create();
    Queue::fake();

    deliver($this, pushedItem())->assertOk()->assertJsonPath('outcome', 'created');

    $received = ListingCopy::query()->sole()->received_at;
    $this->travel(5)->minutes();

    deliver($this, pushedItem())->assertOk()->assertJsonPath('outcome', 'duplicate');

    expect(ListingCopy::count())->toBe(1)
        ->and(ListingCopy::query()->sole()->received_at)->toEqual($received);

    // One applied batch, one notification — a duplicate is not a change.
    Queue::assertPushed(SendChangeNotification::class, 1);

    // A newer updated_at for the same id is a change, not a repeat.
    deliver($this, pushedItem(overrides: ['updated_at' => '2026-09-01T11:00:00Z', 'listing' => ['name' => 'OASIS II']]))
        ->assertOk()
        ->assertJsonPath('outcome', 'updated');

    expect(ListingCopy::query()->sole()->name)->toBe('OASIS II');
})->group('API-11');

test('a tombstone withdraws the copy, and its replay is a duplicate', function () {
    deliver($this, pushedItem())->assertOk();

    deliver($this, pushedTombstone())->assertOk()->assertJsonPath('outcome', 'tombstoned');

    $copy = ListingCopy::query()->sole();

    expect($copy->tombstoned_at)->not->toBeNull()
        ->and($copy->status->value)->toBe('withdrawn')
        ->and($copy->listing_updated_at->format('Y-m-d\TH:i:s\Z'))->toBe('2026-09-02T09:00:00Z');

    deliver($this, pushedTombstone())->assertOk()->assertJsonPath('outcome', 'duplicate');

    expect(Activity::query()->where('event', 'listing_tombstoned')->count())->toBe(1);
})->group('API-11', 'ID-7');

test('a re-shared listing arriving with its original updated_at after a tombstone is applied, not deduplicated', function () {
    deliver($this, pushedItem())->assertOk();
    deliver($this, pushedTombstone())->assertOk()->assertJsonPath('outcome', 'tombstoned');

    // The authority re-shared it: the feed (and so the push) carries the
    // listing's own updated_at again — the copy's state, not a receipt
    // log, is what decides a repeat.
    deliver($this, pushedItem())->assertOk()->assertJsonPath('outcome', 'updated');

    expect(ListingCopy::query()->sole()->tombstoned_at)->toBeNull();
})->group('API-11', 'API-3');

test('a push-subscribed partner is still polled to reconcile, once a day', function () {
    $sync = app(SyncService::class);

    $polled = FederationPartner::factory()->verified()->create(['last_ok_at' => now()->subHours(2)]);
    $subscribedRecently = FederationPartner::factory()->verified()->create(['push_subscribed_at' => now(), 'last_ok_at' => now()->subHours(2)]);
    $subscribedDayAgo = FederationPartner::factory()->verified()->create(['push_subscribed_at' => now(), 'last_ok_at' => now()->subHours(24)]);
    $subscribedNeverPolled = FederationPartner::factory()->verified()->create(['push_subscribed_at' => now(), 'last_ok_at' => null]);
    $subscribedButFailing = FederationPartner::factory()->verified()->create([
        'push_subscribed_at' => now(),
        'last_ok_at' => now()->subDays(3),
        'last_attempted_at' => now()->subMinutes(10),
        'consecutive_failures' => 2,
    ]);

    expect($sync->isDue($polled))->toBeTrue()
        ->and($sync->isDue($subscribedRecently))->toBeFalse()
        ->and($sync->isDue($subscribedDayAgo))->toBeTrue()
        ->and($sync->isDue($subscribedNeverPolled))->toBeTrue()
        // The failure backoff still governs a subscribed partner.
        ->and($sync->isDue($subscribedButFailing))->toBeFalse();

    $this->artisan('openyacht:sync', ['--partner' => $subscribedRecently->domain])
        ->expectsOutputToContain('daily reconciliation poll not yet due')
        ->assertSuccessful();
})->group('API-11', 'API-2');
