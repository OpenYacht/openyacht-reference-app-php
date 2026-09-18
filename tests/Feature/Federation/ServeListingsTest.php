<?php

use App\Enums\ListingStatus;
use App\Enums\TrustLevel;
use App\Models\CharterYacht;
use App\Models\FederationPartner;
use App\Models\ListingCopy;
use App\Models\SaleYacht;
use App\Models\Vessel;

const NODE_HOST = 'this-node.example';
const NODE_BASE = 'https://this-node.example';

beforeEach(function () {
    config(['openyacht.domain' => NODE_HOST]);

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

function signedGet(object $test, string $pathWithQuery)
{
    // A plain GET: getJson() would send a JSON body whose hash differs
    // from the signed empty-body hash.
    return $test->get(NODE_BASE.$pathWithQuery, federationSignedHeaders(
        $test->partner->domain,
        $test->partnerKeypair['key_id'],
        $test->partnerKeypair['secret_key'],
        'GET',
        $pathWithQuery,
        NODE_HOST,
    ));
}

test('listings require a signature', function () {
    $this->get(NODE_BASE.'/openyacht/v1/listings')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'SIGNATURE_INVALID');
})->group('FP-6', 'API-9');

test('a cold sync serves active listings as complete objects and never drafts or copies', function () {
    $active = SaleYacht::factory()->active()->create();
    SaleYacht::factory()->create();
    SaleYacht::factory()->terminal()->create();
    ListingCopy::factory()->create();

    $response = signedGet($this, '/openyacht/v1/listings')->assertOk();

    $data = $response->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['id'])->toBe($active->canonicalUri());

    // Complete objects, null for missing (LS-1): every top-level key and
    // the full specification key set are present.
    expect(array_keys($data[0]))->toContain(
        'id', 'type', 'status', 'updated_at', 'listed_at', 'condition',
        'agreement', 'vessel', 'listing', 'specifications', 'descriptions',
        'features', 'media', 'charter', 'usage', 'compliance',
    );
    expect($data[0]['specifications'])->toHaveKeys(['lod_m', 'gross_tonnage', 'engines', 'cabin_config'])
        ->and($data[0]['specifications']['lod_m'])->toBeNull()
        ->and($data[0]['charter'])->toBeNull();

    // Money is strings with ISO 4217 codes, never floats (API-12).
    expect($data[0]['listing']['price']['amount'])->toBeString()
        ->and($data[0]['listing']['price']['currency'])->toMatch('/^[A-Z]{3}$/');

    expect($response->json('meta.protocol_version'))->toBe('1.0')
        ->and($response->json('meta.generated_at'))->not->toBeNull();
})->group('LS-1', 'LS-7', 'ID-4', 'API-1', 'API-12');

test('updated_since includes tombstones for listings that became invisible', function () {
    $active = SaleYacht::factory()->active()->create();
    $withdrawn = SaleYacht::factory()->active()->create();
    $withdrawn->transitionTo(ListingStatus::Withdrawn);

    $since = now()->subHour()->utc()->format('Y-m-d\TH:i:s\Z');
    $response = signedGet($this, '/openyacht/v1/listings?updated_since='.urlencode($since))->assertOk();

    $data = collect($response->json('data'));
    $tombstone = $data->firstWhere('id', $withdrawn->canonicalUri());

    expect($data)->toHaveCount(2)
        ->and($tombstone['tombstone'])->toBeTrue()
        ->and($tombstone['status'])->toBe('withdrawn')
        ->and($tombstone)->toHaveKey('updated_at')
        ->and($data->firstWhere('id', $active->canonicalUri()))->not->toHaveKey('tombstone');
})->group('API-2', 'API-3');

test('pagination is opaque-cursor based and the last page has no cursor', function () {
    SaleYacht::factory()->active()->count(3)->create();

    $seen = [];
    $path = '/openyacht/v1/listings?page_size=2';

    $first = signedGet($this, $path)->assertOk();
    $seen = array_merge($seen, array_column($first->json('data'), 'id'));
    $cursor = $first->json('meta.next_cursor');

    expect($cursor)->toBeString();

    $second = signedGet($this, $path.'&cursor='.urlencode($cursor))->assertOk();
    $seen = array_merge($seen, array_column($second->json('data'), 'id'));

    expect($second->json('meta'))->not->toHaveKey('next_cursor')
        ->and(array_unique($seen))->toHaveCount(3);
})->group('API-2');

test('field groups gate the payload server-side', function () {
    SaleYacht::factory()->active()
        ->for(Vessel::factory()->withIdentifiers())
        ->create();

    $this->partner->update(['field_groups' => []]);

    $gated = signedGet($this, '/openyacht/v1/listings')->json('data.0');

    expect($gated['listing']['price']['amount'])->toBeNull()
        ->and($gated['listing']['price']['currency'])->toBeNull()
        ->and($gated['listing']['price_history'])->toBe([])
        ->and($gated['listing']['location']['marina'])->toBeNull()
        ->and($gated['listing']['location']['coordinates'])->toBeNull()
        ->and($gated['vessel']['hin'])->toBeNull()
        ->and($gated['vessel']['mmsi'])->toBeNull()
        ->and($gated['listing']['location']['display'])->not->toBeNull();

    $this->partner->update(['field_groups' => null]);

    $full = signedGet($this, '/openyacht/v1/listings')->json('data.0');

    expect($full['listing']['price']['amount'])->not->toBeNull()
        ->and($full['listing']['price_history'])->not->toBe([])
        ->and($full['listing']['location']['marina'])->not->toBeNull()
        ->and($full['vessel']['hin'])->not->toBeNull();
})->group('LS-14', 'API-5');

test('unknown builder, model, and location serialise as null, not empty objects', function () {
    SaleYacht::factory()->active()
        ->for(Vessel::factory()->state([
            'builder_name' => null,
            'builder_slug' => null,
            'model_name' => null,
        ]))
        ->create([
            'location_display' => null,
            'location_city' => null,
            'location_state' => null,
            'location_country' => null,
            'location_marina' => null,
            'location_lat' => null,
            'location_lon' => null,
        ]);

    $item = signedGet($this, '/openyacht/v1/listings')->json('data.0');

    // The schema's vocab def anchors on a non-null name and the location
    // def on a non-null display: absent data is null, never {name: null}.
    expect($item['vessel']['builder'])->toBeNull()
        ->and($item['vessel']['model'])->toBeNull()
        ->and($item['listing']['location'])->toBeNull();
})->group('LS-1');

test('every feature carries all four keys, quantity null when unstated or stored before the field existed', function () {
    SaleYacht::factory()->active()->create([
        'features' => [
            ['category' => 'toys', 'name' => 'Seabob', 'slug' => 'seabob', 'quantity' => 2],
            ['category' => 'comfort', 'name' => 'Air conditioning', 'slug' => 'air-conditioning'],
            ['name' => 'Fictional tender'],
        ],
    ]);

    expect(signedGet($this, '/openyacht/v1/listings')->json('data.0.features'))->toBe([
        ['category' => 'toys', 'name' => 'Seabob', 'slug' => 'seabob', 'quantity' => 2],
        ['category' => 'comfort', 'name' => 'Air conditioning', 'slug' => 'air-conditioning', 'quantity' => null],
        ['category' => null, 'name' => 'Fictional tender', 'slug' => null, 'quantity' => null],
    ]);
})->group('LS-1');

test('a category stored without a name serialises as null', function () {
    SaleYacht::factory()->active()->create([
        'specifications' => ['power_or_sail' => 'power', 'category' => ['name' => null, 'slug' => null]],
    ]);

    expect(signedGet($this, '/openyacht/v1/listings')->json('data.0.specifications'))
        ->toHaveKey('category', null);
})->group('LS-1');

test('adopting feature quantity stamps exactly the listings whose wire form changed', function () {
    $this->travelTo(now()->subDay());
    $withFeatures = SaleYacht::factory()->active()->create();
    $charterWithFeatures = CharterYacht::factory()->active()->create();
    $namelessCategory = SaleYacht::factory()->active()->create([
        'features' => [],
        'specifications' => ['power_or_sail' => 'power', 'category' => ['name' => null, 'slug' => null]],
    ]);
    $unaffected = SaleYacht::factory()->active()->create(['features' => []]);
    $tombstoned = SaleYacht::factory()->active()->create(['status' => ListingStatus::Sold]);
    $this->travelBack();

    $before = $unaffected->fresh()->federation_updated_at;

    (require database_path('migrations/2026_09_18_200702_stamp_federation_updated_at_for_feature_quantity.php'))->up();

    expect($withFeatures->fresh()->federation_updated_at->gt($before))->toBeTrue()
        ->and($charterWithFeatures->fresh()->federation_updated_at->gt($before))->toBeTrue()
        ->and($namelessCategory->fresh()->federation_updated_at->gt($before))->toBeTrue()
        ->and($unaffected->fresh()->federation_updated_at->eq($before))->toBeTrue()
        ->and($tombstoned->fresh()->federation_updated_at->gt($before))->toBeFalse();
})->group('API-2');

test('a listing with no imagery has a null profile, never a placeholder', function () {
    SaleYacht::factory()->active()->create();

    $media = signedGet($this, '/openyacht/v1/listings')->json('data.0.media');

    expect($media['profile'])->toBeNull()
        ->and($media['gallery'])->toBe([])
        ->and($media['documents'])->toBe([]);
})->group('LS-8');

test('the single-listing endpoint dereferences canonical URIs', function () {
    $yacht = SaleYacht::factory()->active()->create();

    signedGet($this, "/openyacht/v1/listings/{$yacht->uuid}")
        ->assertOk()
        ->assertJsonPath('id', $yacht->canonicalUri())
        ->assertJsonPath('status', 'active');
})->group('ID-1');

test('drafts dereference as not found', function () {
    $draft = SaleYacht::factory()->create();

    signedGet($this, "/openyacht/v1/listings/{$draft->uuid}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND');
})->group('LS-7', 'API-9');

test('terminal listings stay dereferenceable during retention, then are gone', function () {
    $sold = SaleYacht::factory()->active()->create();
    $sold->transitionTo(ListingStatus::Sold);

    signedGet($this, "/openyacht/v1/listings/{$sold->uuid}")
        ->assertOk()
        ->assertJsonPath('status', 'sold');

    $this->travel(13)->months();

    signedGet($this, "/openyacht/v1/listings/{$sold->uuid}")
        ->assertGone()
        ->assertJsonPath('error.code', 'GONE');
})->group('API-9');

test('a charter listing serialises with the schema type conditional: charter block, null price, empty history', function () {
    $charter = CharterYacht::factory()->active()->create();

    $response = signedGet($this, '/openyacht/v1/listings')->assertOk();
    $item = $response->json('data.0');

    expect($item['id'])->toBe($charter->canonicalUri())
        ->and($item['type'])->toBe('charter')
        // Charter has no asking price by design; pricing is the rate block.
        ->and($item['listing']['price'])->toBeNull()
        ->and($item['listing']['price_history'])->toBe([]);

    // Shape-complete charter block: five keys always present (LS-1).
    expect(array_keys($item['charter']))->toBe([
        'rates', 'operating_areas', 'summer_base_port', 'winter_base_port', 'crew',
    ]);

    // Every rate carries all ten keys, money as digit strings (API-12).
    $rate = $item['charter']['rates'][0];
    expect(array_keys($rate))->toBe([
        'season', 'rate_type', 'amount_min', 'amount_max', 'currency',
        'contract_terms', 'apa_percent', 'vat_percent', 'valid_from', 'valid_to',
    ])
        ->and($rate['amount_min'])->toBeString()->toMatch('/^\d+(\.\d+)?$/')
        ->and($rate['currency'])->toMatch('/^[A-Z]{3}$/');

    // Every crew member carries all six keys; the TBA entry has the
    // personal fields null.
    $tba = collect($item['charter']['crew'])->firstWhere('tba', true);
    expect(array_keys($tba))->toBe(['role', 'name', 'nationality', 'bio', 'photo_url', 'tba'])
        ->and($tba['name'])->toBeNull();
})->group('LS-1', 'LS-10');

test('the feed unions sale and charter listings and the cursor walks across both types', function () {
    SaleYacht::factory()->active()->count(2)->create();
    CharterYacht::factory()->active()->count(2)->create();

    $seen = [];
    $types = [];
    $path = '/openyacht/v1/listings?page_size=3';

    $first = signedGet($this, $path)->assertOk();
    $seen = array_merge($seen, array_column($first->json('data'), 'id'));
    $types = array_merge($types, array_column($first->json('data'), 'type'));
    $cursor = $first->json('meta.next_cursor');

    expect($first->json('data'))->toHaveCount(3)
        ->and($cursor)->toBeString();

    $second = signedGet($this, $path.'&cursor='.urlencode($cursor))->assertOk();
    $seen = array_merge($seen, array_column($second->json('data'), 'id'));
    $types = array_merge($types, array_column($second->json('data'), 'type'));

    expect($second->json('meta'))->not->toHaveKey('next_cursor')
        ->and(array_unique($seen))->toHaveCount(4)
        ->and(array_count_values($types))->toBe(['sale' => 2, 'charter' => 2]);
})->group('API-1', 'API-2');

test('a withdrawn charter listing tombstones in updated_since like any other', function () {
    $charter = CharterYacht::factory()->active()->create();
    $charter->transitionTo(ListingStatus::Withdrawn);

    $since = now()->subHour()->utc()->format('Y-m-d\TH:i:s\Z');
    $data = signedGet($this, '/openyacht/v1/listings?updated_since='.urlencode($since))
        ->assertOk()
        ->json('data');

    expect($data[0]['id'])->toBe($charter->canonicalUri())
        ->and($data[0]['tombstone'])->toBeTrue()
        ->and($data[0]['status'])->toBe('withdrawn');
})->group('API-2', 'API-3');

test('the single-listing endpoint dereferences charter canonical URIs', function () {
    $charter = CharterYacht::factory()->active()->create();

    signedGet($this, "/openyacht/v1/listings/{$charter->uuid}")
        ->assertOk()
        ->assertJsonPath('id', $charter->canonicalUri())
        ->assertJsonPath('type', 'charter');
})->group('ID-1');

test('charter rates sit under the pricing field group and are emptied server-side without it', function () {
    CharterYacht::factory()->active()->create();

    $this->partner->update(['field_groups' => []]);

    $gated = signedGet($this, '/openyacht/v1/listings')->json('data.0');

    expect($gated['charter']['rates'])->toBe([])
        // The rest of the block is ungated.
        ->and($gated['charter']['operating_areas'])->not->toBe([])
        ->and($gated['charter']['summer_base_port'])->not->toBeNull();

    $this->partner->update(['field_groups' => null]);

    $full = signedGet($this, '/openyacht/v1/listings')->json('data.0');

    expect($full['charter']['rates'])->not->toBe([]);
})->group('LS-14', 'API-5');

test('crew is distributed only while the node holds an attestation', function () {
    CharterYacht::factory()->active()->unattested()->create();

    $withheld = signedGet($this, '/openyacht/v1/listings')->json('data.0');

    // Withheld crew is an empty list — indistinguishable from "no crew
    // data", never a partial or flagged payload.
    expect($withheld['charter']['crew'])->toBe([]);

    CharterYacht::query()->first()->update(['crew_attested_at' => now()]);

    $attested = signedGet($this, '/openyacht/v1/listings')->json('data.0');

    expect($attested['charter']['crew'])->not->toBe([]);
})->group('LS-15');

test('a provisional partner authenticates but receives no listings', function () {
    $this->partner->update(['trust_level' => TrustLevel::Provisional]);

    signedGet($this, '/openyacht/v1/listings')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'PARTNER_PROVISIONAL');
})->group('FP-13', 'API-9');

test('a blocked partner is rejected outright', function () {
    $this->partner->update(['trust_level' => TrustLevel::Blocked]);

    signedGet($this, '/openyacht/v1/listings')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'PARTNER_BLOCKED');
})->group('FP-9', 'API-9');
