<?php

use App\Enums\ListingStatus;
use App\Models\SaleYacht;

beforeEach(function () {
    config(['openyacht.domain' => 'this-node.example']);
});

test('a canonical UUID is minted once at creation and the URI never changes', function () {
    $yacht = SaleYacht::factory()->create();

    expect($yacht->uuid)->not->toBeNull()
        ->and($yacht->canonicalUri())
        ->toBe("https://this-node.example/openyacht/v1/listings/{$yacht->uuid}");

    $uuid = $yacht->uuid;
    $yacht->update(['name' => 'RENAMED', 'price_amount' => '999000']);
    $yacht->transitionTo(ListingStatus::Active);

    expect($yacht->fresh()->uuid)->toBe($uuid);

    // uuid is not mass-assignable, and direct assignment is rejected too.
    expect(fn () => tap($yacht, fn (SaleYacht $y) => $y->uuid = 'something-else')->save())
        ->toThrow(InvalidArgumentException::class);
})->group('ID-1');

test('the lifecycle allows only the defined transitions', function () {
    $yacht = SaleYacht::factory()->create();

    expect($yacht->status)->toBe(ListingStatus::Draft);

    $yacht->transitionTo(ListingStatus::Active);
    expect($yacht->status)->toBe(ListingStatus::Active)
        ->and($yacht->listed_at)->not->toBeNull();

    $yacht->transitionTo(ListingStatus::UnderOffer);
    $yacht->transitionTo(ListingStatus::Active);
    $yacht->transitionTo(ListingStatus::Sold);

    expect(fn () => $yacht->transitionTo(ListingStatus::Active))
        ->toThrow(InvalidArgumentException::class);
})->group('ID-8');

test('a draft cannot jump straight to a terminal state', function () {
    $yacht = SaleYacht::factory()->create();

    expect(fn () => $yacht->transitionTo(ListingStatus::Sold))
        ->toThrow(InvalidArgumentException::class);
})->group('ID-8');

test('price history is append-only, most recent first, first entry equals current price', function () {
    $yacht = SaleYacht::factory()->create(['price_amount' => '1000000', 'price_currency' => 'EUR']);

    expect($yacht->priceHistory()->count())->toBe(1);

    $this->travel(1)->day();
    $yacht->update(['price_amount' => '900000']);

    $this->travel(1)->day();
    $yacht->update(['price_amount' => '850000']);

    $history = $yacht->refresh()->priceHistory;

    expect($history)->toHaveCount(3)
        ->and($history->first()->amount)->toBe('850000')
        ->and($history->first()->amount)->toBe($yacht->price_amount)
        ->and($history->pluck('amount')->all())->toBe(['850000', '900000', '1000000']);
})->group('LS-10');

test('a non-price edit does not append price history', function () {
    $yacht = SaleYacht::factory()->create();

    $yacht->update(['summary' => 'New summary.']);

    expect($yacht->priceHistory()->count())->toBe(1);
})->group('LS-10');

test('every federation-visible change moves federation_updated_at forward', function () {
    $yacht = SaleYacht::factory()->create();
    $initial = $yacht->federation_updated_at;

    $this->travel(1)->hour();
    $yacht->update(['name' => 'CHANGED']);

    expect($yacht->fresh()->federation_updated_at->isAfter($initial))->toBeTrue();
})->group('API-4');
