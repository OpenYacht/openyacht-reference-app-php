<?php

use App\Enums\TrustLevel;
use App\Models\FederationPartner;
use App\Models\User;
use App\Services\Federation\InvalidWellKnownDocument;
use App\Services\Federation\PartnerService;
use Illuminate\Support\Facades\Http;
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
                'key_id' => 'a1b2c3d4e5f60718',
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
        ->and($partner->publishedKeys())->toHaveKey('a1b2c3d4e5f60718')
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
            'keys' => [
                [
                    'key_id' => 'ffffffffffffffff',
                    'algorithm' => 'ed25519',
                    'public_key' => base64_encode(str_repeat('n', 32)),
                    'created_at' => '2026-08-21T10:30:00Z',
                ],
            ],
        ])),
    ]);

    $partner = app(PartnerService::class)->refreshKeys($partner);

    expect($partner->trust_level)->toBe(TrustLevel::Verified)
        ->and($partner->publishedKeys())->toHaveKey('ffffffffffffffff');
});

test('a changed node UUID downgrades the partner to provisional and notifies administrators', function () {
    $approver = User::factory()->create();
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
})->group('FP-11');

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
