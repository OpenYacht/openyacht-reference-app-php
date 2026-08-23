<?php

use App\Services\Federation\BuilderRegistry;
use Illuminate\Support\Facades\Http;

test('the vendored registry loads without any network access', function () {
    Http::fake(fn () => throw new RuntimeException('The registry must never be fetched at request time.'));

    $registry = app(BuilderRegistry::class);

    expect($registry->all())->not->toBeEmpty()
        ->and($registry->version())->toMatch('/^\d{4}\.\d{2}\.\d+$/');

    Http::assertNothingSent();
})->group('LS-13');

test('known slugs resolve to their canonical registry entries', function () {
    $registry = app(BuilderRegistry::class);

    expect($registry->has('benetti'))->toBeTrue()
        ->and($registry->canonicalName('benetti'))->toBe('Benetti');
})->group('LS-11');

test('unknown slugs are not registry members', function () {
    $registry = app(BuilderRegistry::class);

    expect($registry->has('definitely-not-a-builder'))->toBeFalse()
        ->and($registry->get('definitely-not-a-builder'))->toBeNull()
        ->and($registry->canonicalName('definitely-not-a-builder'))->toBeNull();
})->group('LS-12');
