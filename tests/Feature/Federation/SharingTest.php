<?php

use App\Enums\Audience;
use App\Enums\FieldGroup;
use App\Enums\SharingScope;
use App\Models\FederationPartner;
use App\Models\PartnerGroup;
use App\Models\SaleYacht;
use App\Models\VisibilityEvent;
use App\Services\Federation\SharingService;

beforeEach(function () {
    config(['openyacht.domain' => 'this-node.example']);

    $this->partnerKeypair = federationTestKeypair();
    $this->partner = FederationPartner::factory()->verified()->create([
        'domain' => 'openyacht.partner.example',
        'keys_json' => [[
            'key_id' => $this->partnerKeypair['key_id'],
            'algorithm' => 'ed25519',
            'public_key' => $this->partnerKeypair['public_key'],
            'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]],
    ]);
});

function sharingGet(object $test, string $pathWithQuery, ?FederationPartner $partner = null, ?array $keypair = null)
{
    $partner ??= $test->partner;
    $keypair ??= $test->partnerKeypair;

    return $test->get('https://this-node.example'.$pathWithQuery, federationSignedHeaders(
        $partner->domain,
        $keypair['key_id'],
        $keypair['secret_key'],
        'GET',
        $pathWithQuery,
        'this-node.example',
    ));
}

function sharingWatermark(): string
{
    return urlencode(now()->utc()->format('Y-m-d\TH:i:s\Z'));
}

test('unshare delivers exactly one tombstone, re-share exactly one update', function () {
    $yacht = SaleYacht::factory()->active()->create();
    $sharing = app(SharingService::class);

    $this->travel(1)->minutes();
    $firstWatermark = sharingWatermark();
    $this->travel(1)->minutes();

    $sharing->setAudience($yacht, Audience::None);
    $transitionAt = now()->utc()->format('Y-m-d\TH:i:s\Z');

    $data = sharingGet($this, '/openyacht/v1/listings?updated_since='.$firstWatermark)
        ->assertOk()
        ->json('data');

    // A tombstone indistinguishable from a real withdrawal, timestamped
    // at the transition — never the listing's own updated_at.
    expect($data)->toHaveCount(1)
        ->and($data[0]['tombstone'])->toBeTrue()
        ->and($data[0]['status'])->toBe('withdrawn')
        ->and($data[0]['updated_at'])->toBe($transitionAt);

    $this->travel(1)->minutes();
    $secondWatermark = sharingWatermark();
    $this->travel(1)->minutes();

    $sharing->setAudience($yacht, Audience::Everyone);

    $data = sharingGet($this, '/openyacht/v1/listings?updated_since='.$secondWatermark)
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0])->not->toHaveKey('tombstone')
        ->and($data[0]['id'])->toBe($yacht->canonicalUri())
        ->and($data[0]['status'])->toBe('active');

    // Against the original watermark the same partner sees the listing —
    // the latest transition wins, whatever the poll cadence was.
    $data = sharingGet($this, '/openyacht/v1/listings?updated_since='.$firstWatermark)
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0])->not->toHaveKey('tombstone');
})->group('API-3');

test('cold sync serves only the listings visible to the requesting partner', function () {
    $everyone = SaleYacht::factory()->active()->create();
    $selectedForUs = SaleYacht::factory()->active()->create();
    $selectedForOther = SaleYacht::factory()->active()->create();
    $noOne = SaleYacht::factory()->active()->create();

    $other = FederationPartner::factory()->verified()->create();
    $sharing = app(SharingService::class);

    $sharing->setAudience($selectedForUs, Audience::Selected, [$this->partner->id]);
    $sharing->setAudience($selectedForOther, Audience::Selected, [$other->id]);
    $sharing->setAudience($noOne, Audience::None);

    $ids = collect(sharingGet($this, '/openyacht/v1/listings')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($everyone->canonicalUri())
        ->toContain($selectedForUs->canonicalUri())
        ->not->toContain($selectedForOther->canonicalUri())
        ->not->toContain($noOne->canonicalUri());
})->group('API-2');

test('group membership grants visibility through the selected audience', function () {
    $yacht = SaleYacht::factory()->active()->create();
    $group = PartnerGroup::factory()->create();
    $group->members()->attach($this->partner);

    app(SharingService::class)->setAudience($yacht, Audience::Selected, [], [$group->id]);

    $ids = collect(sharingGet($this, '/openyacht/v1/listings')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($yacht->canonicalUri());
})->group('API-2');

test('a hidden listing dereferences exactly like a nonexistent one', function () {
    $yacht = SaleYacht::factory()->active()->create();
    app(SharingService::class)->setAudience($yacht, Audience::None);

    $hidden = sharingGet($this, "/openyacht/v1/listings/{$yacht->uuid}")->assertNotFound();
    $missing = sharingGet($this, '/openyacht/v1/listings/018f0000-dead-7000-8000-000000000000')->assertNotFound();

    // No leak: identical apart from the per-request envelope metadata.
    expect($hidden->json('error'))->toBe($missing->json('error'));
})->group('LS-7', 'API-9');

test('draft audience changes record no visibility events', function () {
    $draft = SaleYacht::factory()->create();

    $result = app(SharingService::class)->setAudience($draft, Audience::None);

    // Nothing was ever distributed, so there is nothing to tombstone or
    // resurface (LS-7).
    expect($result)->toBe(['hidden' => 0, 'revealed' => 0])
        ->and(VisibilityEvent::count())->toBe(0)
        ->and($draft->refresh()->audience)->toBe(Audience::None);
})->group('LS-7');

test('an audience change never moves the wire timestamp or disturbs other partners', function () {
    $yacht = SaleYacht::factory()->active()->create();
    $before = $yacht->federation_updated_at;

    $otherKeypair = federationTestKeypair();
    $other = FederationPartner::factory()->verified()->create([
        'domain' => 'openyacht.other.example',
        'keys_json' => [[
            'key_id' => $otherKeypair['key_id'],
            'algorithm' => 'ed25519',
            'public_key' => $otherKeypair['public_key'],
            'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]],
    ]);

    $this->travel(1)->minutes();
    $watermark = sharingWatermark();
    $this->travel(1)->minutes();

    // Hiding from one partner must not resend to — or tombstone for —
    // anyone else: only the affected partner's effective timestamp moves.
    app(SharingService::class)->setAudience($yacht, Audience::Selected, [$other->id]);

    expect($yacht->refresh()->federation_updated_at)->toEqual($before);

    expect(sharingGet($this, '/openyacht/v1/listings?updated_since='.$watermark, $other, $otherKeypair)
        ->assertOk()->json('data'))->toBe([]);

    expect(collect(sharingGet($this, '/openyacht/v1/listings?updated_since='.$watermark)
        ->assertOk()->json('data'))->pluck('tombstone'))->toContain(true);
})->group('API-2', 'API-3');

test('group membership changes replay through the event log without touching listings', function () {
    $yacht = SaleYacht::factory()->active()->create();
    $group = PartnerGroup::factory()->create();
    $sharing = app(SharingService::class);

    $sharing->setAudience($yacht, Audience::Selected, [], [$group->id]);

    $this->travel(1)->minutes();
    $watermark = sharingWatermark();
    $this->travel(1)->minutes();

    // Joining the group surfaces every listing selecting it against the
    // partner's old watermark.
    $sharing->replaceGroupMembers($group, [$this->partner->id]);

    $data = sharingGet($this, '/openyacht/v1/listings?updated_since='.$watermark)->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0])->not->toHaveKey('tombstone')
        ->and($data[0]['id'])->toBe($yacht->canonicalUri());

    $this->travel(1)->minutes();
    $watermark = sharingWatermark();
    $this->travel(1)->minutes();

    // Leaving it tombstones the listing for the removed partner.
    $sharing->replaceGroupMembers($group, []);

    $data = sharingGet($this, '/openyacht/v1/listings?updated_since='.$watermark)->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['tombstone'])->toBeTrue()
        ->and($data[0]['status'])->toBe('withdrawn');
})->group('API-3');

test('a partner removed from a group but still individually selected keeps visibility', function () {
    $yacht = SaleYacht::factory()->active()->create();
    $group = PartnerGroup::factory()->create();
    $group->members()->attach($this->partner);
    $sharing = app(SharingService::class);

    $sharing->setAudience($yacht, Audience::Selected, [$this->partner->id], [$group->id]);

    $result = $sharing->replaceGroupMembers($group, []);

    // Still visible another way — no transition, no tombstone.
    expect($result)->toBe(['hidden' => 0, 'revealed' => 0]);

    $ids = collect(sharingGet($this, '/openyacht/v1/listings')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($yacht->canonicalUri());
})->group('API-3');

test('deleting a group empties membership first so the tombstones land in the log', function () {
    $yacht = SaleYacht::factory()->active()->create();
    $group = PartnerGroup::factory()->create();
    $group->members()->attach($this->partner);
    $sharing = app(SharingService::class);

    $sharing->setAudience($yacht, Audience::Selected, [], [$group->id]);

    $this->travel(1)->minutes();
    $watermark = sharingWatermark();
    $this->travel(1)->minutes();

    $sharing->deleteGroup($group);

    expect(PartnerGroup::count())->toBe(0);

    $data = sharingGet($this, '/openyacht/v1/listings?updated_since='.$watermark)->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['tombstone'])->toBeTrue();
})->group('API-3');

test('a curated partner receives only explicitly shared listings, never the everyone audience', function () {
    $this->partner->update(['sharing_scope' => SharingScope::Curated]);

    $everyone = SaleYacht::factory()->active()->create();
    $directlyShared = SaleYacht::factory()->active()->create();
    $groupShared = SaleYacht::factory()->active()->create();

    $group = PartnerGroup::factory()->create();
    $group->members()->attach($this->partner);

    $sharing = app(SharingService::class);
    $sharing->setAudience($directlyShared, Audience::Selected, [$this->partner->id]);
    $sharing->setAudience($groupShared, Audience::Selected, [], [$group->id]);

    $ids = collect(sharingGet($this, '/openyacht/v1/listings')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($directlyShared->canonicalUri())
        ->toContain($groupShared->canonicalUri())
        ->not->toContain($everyone->canonicalUri());

    // The unshared listing dereferences like a nonexistent one — no leak.
    sharingGet($this, "/openyacht/v1/listings/{$everyone->uuid}")->assertNotFound();
})->group('API-2');

test('narrowing a partner to curated tombstones its everyone-only listings; widening resurfaces them', function () {
    $everyone = SaleYacht::factory()->active()->create();
    $selected = SaleYacht::factory()->active()->create();
    $sharing = app(SharingService::class);

    $sharing->setAudience($selected, Audience::Selected, [$this->partner->id]);
    $wireTimestamp = $everyone->refresh()->federation_updated_at;

    $this->travel(1)->minutes();
    $watermark = sharingWatermark();
    $this->travel(1)->minutes();

    $result = $sharing->setSharingScope($this->partner->refresh(), SharingScope::Curated);

    // Exactly the everyone-only listing tombstones; the explicit share
    // survives, and no listing's wire timestamp moves.
    expect($result)->toBe(['hidden' => 1, 'revealed' => 0])
        ->and($everyone->refresh()->federation_updated_at)->toEqual($wireTimestamp);

    $data = sharingGet($this, '/openyacht/v1/listings?updated_since='.$watermark)->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['tombstone'])->toBeTrue()
        ->and($data[0]['status'])->toBe('withdrawn');

    $this->travel(1)->minutes();
    $watermark = sharingWatermark();
    $this->travel(1)->minutes();

    $result = $sharing->setSharingScope($this->partner->refresh(), SharingScope::Standard);

    expect($result)->toBe(['hidden' => 0, 'revealed' => 1]);

    $data = sharingGet($this, '/openyacht/v1/listings?updated_since='.$watermark)->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0])->not->toHaveKey('tombstone')
        ->and($data[0]['id'])->toBe($everyone->canonicalUri());
})->group('API-2', 'API-3');

test('an explicit share on an everyone listing reaches curated partners without disturbing standard ones', function () {
    $this->partner->update(['sharing_scope' => SharingScope::Curated]);
    $standard = FederationPartner::factory()->verified()->create();

    $yacht = SaleYacht::factory()->active()->create();
    $group = PartnerGroup::factory()->create();
    $group->members()->attach($this->partner);

    // Pivots are additive: ticking the group extends the everyone
    // audience to its curated members; standard members were already
    // visible, so only the curated partner gets a transition.
    $result = app(SharingService::class)->setAudience($yacht, Audience::Everyone, [], [$group->id]);

    expect($result)->toBe(['hidden' => 0, 'revealed' => 1])
        ->and(VisibilityEvent::query()->pluck('federation_partner_id')->all())->toBe([$this->partner->id])
        ->and($standard->refresh()->sharing_scope)->toBe(SharingScope::Standard);

    $ids = collect(sharingGet($this, '/openyacht/v1/listings')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($yacht->canonicalUri());
})->group('API-2', 'API-3');

test('direct shares replaced from the partner picker replay through the event log', function () {
    $this->partner->update(['sharing_scope' => SharingScope::Curated]);
    $yacht = SaleYacht::factory()->active()->create();
    $sharing = app(SharingService::class);

    $this->travel(1)->minutes();
    $watermark = sharingWatermark();
    $this->travel(1)->minutes();

    $sharing->replaceDirectSharesForPartner($this->partner, 'sale', [$yacht->uuid]);

    $data = sharingGet($this, '/openyacht/v1/listings?updated_since='.$watermark)->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0])->not->toHaveKey('tombstone')
        ->and($data[0]['id'])->toBe($yacht->canonicalUri());

    $this->travel(1)->minutes();
    $watermark = sharingWatermark();
    $this->travel(1)->minutes();

    $sharing->replaceDirectSharesForPartner($this->partner, 'sale', []);

    $data = sharingGet($this, '/openyacht/v1/listings?updated_since='.$watermark)->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['tombstone'])->toBeTrue()
        ->and($data[0]['status'])->toBe('withdrawn');
})->group('API-2', 'API-3');

test('a grants change resends re-gated payloads on the next poll', function () {
    SaleYacht::factory()->active()->create();

    $this->travel(1)->minutes();
    $watermark = sharingWatermark();
    $this->travel(1)->minutes();

    // Nothing changed yet: the delta is empty.
    expect(sharingGet($this, '/openyacht/v1/listings?updated_since='.$watermark)->assertOk()->json('data'))
        ->toBe([]);

    // Withhold pricing; the refreshed event lifts the listing past the
    // watermark for this partner only (API-4) — served as a normal
    // listing (the hidden-tombstone branch keys on hidden specifically),
    // already re-gated.
    $this->partner->update(['field_groups' => [FieldGroup::Documents->value]]);
    $refreshed = app(SharingService::class)->refreshPartnerFeed($this->partner->refresh());

    expect($refreshed)->toBe(1);

    $data = sharingGet($this, '/openyacht/v1/listings?updated_since='.$watermark)->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0])->not->toHaveKey('tombstone')
        ->and($data[0]['listing']['price']['amount'])->toBeNull();
})->group('API-4', 'API-5');
