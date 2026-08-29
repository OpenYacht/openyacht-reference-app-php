<?php

use App\Jobs\SyncExchangeRates;
use App\Models\ApiKey;
use App\Models\CharterYacht;
use App\Models\ExchangeRate;
use App\Models\ImportedYacht;
use App\Models\SaleYacht;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['openyacht.domain' => 'this-node.example']);
    ExchangeRate::flushRateCache();
    $this->apiKey = ApiKey::generate('Price search key', ['yachts:read'])['plaintext'];
});

function seedRates(): void
{
    // EUR is the implicit base (1.0); 1 EUR = 1.10 USD = 0.85 GBP.
    ExchangeRate::factory()->usd(1.10)->create();
    ExchangeRate::factory()->gbp(0.85)->create();
}

test('price filters match across currencies at the current rates', function () {
    seedRates();

    $inRange = SaleYacht::factory()->active()->create(['price_amount' => '1000000', 'price_currency' => 'USD']);
    // €950,000 ≈ $1,045,000 — inside a $900k–$1.1M window.
    $inRangeEur = SaleYacht::factory()->active()->create(['price_amount' => '950000', 'price_currency' => 'EUR']);
    SaleYacht::factory()->active()->create(['price_amount' => '2500000', 'price_currency' => 'USD']);
    SaleYacht::factory()->active()->create(['price_amount' => '2400000', 'price_currency' => 'EUR']);

    $response = $this->getJson('/api/v1/yachts?price_min=900000&price_max=1100000', ['X-API-Key' => $this->apiKey])
        ->assertOk();

    expect(collect($response->json('data'))->pluck('x_key')->all())
        ->toEqualCanonicalizing([$inRange->uuid, $inRangeEur->uuid]);
})->group('demo-node');

test('the filter covers imported listings with the same conversion', function () {
    seedRates();

    $imported = ImportedYacht::factory()->create(['price_amount' => '800000', 'price_currency' => 'GBP']);
    ImportedYacht::factory()->create(['price_amount' => '3000000', 'price_currency' => 'USD']);

    // £800,000 ≈ $1,035,294 at 1 EUR = 0.85 GBP = 1.10 USD.
    $response = $this->getJson('/api/v1/yachts?price_min=1000000&price_max=1100000', ['X-API-Key' => $this->apiKey])
        ->assertOk();

    expect(collect($response->json('data'))->pluck('x_key')->all())
        ->toBe(["imported-{$imported->id}"]);
})->group('demo-node');

test('a priced filter excludes charter listings and price-on-application listings', function () {
    seedRates();

    CharterYacht::factory()->active()->create();
    SaleYacht::factory()->active()->create([
        'price_amount' => null, 'price_currency' => null, 'price_on_application' => true,
    ]);
    $priced = SaleYacht::factory()->active()->create(['price_amount' => '1000000', 'price_currency' => 'USD']);

    $response = $this->getJson('/api/v1/yachts?price_min=1', ['X-API-Key' => $this->apiKey])
        ->assertOk();

    expect(collect($response->json('data'))->pluck('x_key')->all())->toBe([$priced->uuid]);
})->group('demo-node');

test('a listing in a currency with no fetched rate never matches a priced filter', function () {
    seedRates();

    SaleYacht::factory()->active()->create(['price_amount' => '1000000', 'price_currency' => 'XCD']);
    $rated = SaleYacht::factory()->active()->create(['price_amount' => '1000000', 'price_currency' => 'USD']);

    $response = $this->getJson('/api/v1/yachts?price_min=1&price_max=99999999', ['X-API-Key' => $this->apiKey])
        ->assertOk();

    expect(collect($response->json('data'))->pluck('x_key')->all())->toBe([$rated->uuid]);
})->group('demo-node');

test('a priced filter without a rate for the target currency is refused loudly, never served unconverted', function () {
    seedRates();

    SaleYacht::factory()->active()->create(['price_amount' => '1000000', 'price_currency' => 'USD']);

    $this->getJson('/api/v1/yachts?price_min=1&price_currency=THB', ['X-API-Key' => $this->apiKey])
        ->assertUnprocessable();

    $this->getJson('/api/v1/yachts?price_min=1&price_currency=notacurrency', ['X-API-Key' => $this->apiKey])
        ->assertUnprocessable();

    $this->getJson('/api/v1/yachts?price_min=notanumber', ['X-API-Key' => $this->apiKey])
        ->assertUnprocessable();
})->group('demo-node');

test('with no rates fetched at all, any priced filter is a 422', function () {
    SaleYacht::factory()->active()->create(['price_amount' => '1000000', 'price_currency' => 'USD']);

    $this->getJson('/api/v1/yachts?price_min=1&price_currency=USD', ['X-API-Key' => $this->apiKey])
        ->assertUnprocessable();
})->group('demo-node');

test('price sorting orders mixed currencies by converted value, unpriced listings last', function () {
    seedRates();

    $cheap = SaleYacht::factory()->active()->create(['price_amount' => '500000', 'price_currency' => 'USD']);
    // €900,000 ≈ $990,000 — between the two USD listings.
    $middle = SaleYacht::factory()->active()->create(['price_amount' => '900000', 'price_currency' => 'EUR']);
    $dear = SaleYacht::factory()->active()->create(['price_amount' => '2000000', 'price_currency' => 'USD']);
    $charter = CharterYacht::factory()->active()->create();

    $response = $this->getJson('/api/v1/yachts?sort=price', ['X-API-Key' => $this->apiKey])
        ->assertOk();

    expect(collect($response->json('data'))->pluck('x_key')->all())
        ->toBe([$cheap->uuid, $middle->uuid, $dear->uuid, $charter->uuid]);

    $descending = $this->getJson('/api/v1/yachts?sort=price&sort_direction=desc', ['X-API-Key' => $this->apiKey])
        ->assertOk();

    expect(collect($descending->json('data'))->pluck('x_key')->first())->toBe($dear->uuid);
})->group('demo-node');

test('the list carries a followable next link and a 200 per_page ceiling', function () {
    seedRates();
    SaleYacht::factory()->active()->count(3)->create();

    $response = $this->getJson('/api/v1/yachts?per_page=2', ['X-API-Key' => $this->apiKey])
        ->assertOk();

    expect($response->json('links.next'))->toContain('page=2')
        ->and($this->getJson($response->json('links.next'), ['X-API-Key' => $this->apiKey])->json('links.next'))->toBeNull()
        ->and($this->getJson('/api/v1/yachts?per_page=9999', ['X-API-Key' => $this->apiKey])->json('meta.per_page'))->toBe(200);
})->group('demo-node');

test('the exchange rate sync job stores ECB reference rates and their publication date', function () {
    $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
            <Cube>
                <Cube time="2026-08-28">
                    <Cube currency="USD" rate="1.1043"/>
                    <Cube currency="GBP" rate="0.8471"/>
                </Cube>
            </Cube>
        </gesmes:Envelope>
        XML;

    Http::fake(['www.ecb.europa.eu/*' => Http::response($xml)]);

    (new SyncExchangeRates)->handle();

    expect(ExchangeRate::query()->count())->toBe(2)
        ->and(ExchangeRate::rateFor('USD'))->toBe(1.1043)
        ->and(ExchangeRate::rateFor('EUR'))->toBe(1.0)
        ->and(ExchangeRate::query()->where('currency', 'USD')->first()->published_at->toDateString())->toBe('2026-08-28');
})->group('demo-node');

test('a failed ECB fetch fails the job rather than degrading silently', function () {
    Http::fake(['www.ecb.europa.eu/*' => Http::response('', 503)]);

    expect(fn () => (new SyncExchangeRates)->handle())->toThrow(RuntimeException::class);

    expect(ExchangeRate::query()->count())->toBe(0);
})->group('demo-node');
