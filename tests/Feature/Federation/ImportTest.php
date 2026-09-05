<?php

use App\Enums\ListingStatus;
use App\Enums\Role;
use App\Jobs\ImportYachtMedia;
use App\Models\FederationKey;
use App\Models\FederationPartner;
use App\Models\ImportedMedia;
use App\Models\ImportedYacht;
use App\Models\ListingCopy;
use App\Models\User;
use App\Services\Federation\ImportService;
use App\Services\Federation\SyncService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function fakeJpegBytes(int $width = 2400, int $height = 1600): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, 30, 90, 160));
    ob_start();
    imagejpeg($image, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

function importableCopy(array $payloadOverrides = []): ListingCopy
{
    return ListingCopy::factory()->create([
        'payload' => array_replace_recursive([
            'id' => 'https://openyacht.partner.example/openyacht/v1/listings/uuid-1',
            'type' => 'sale',
            'status' => 'active',
            'vessel' => [
                'builder' => ['name' => 'Benetti', 'slug' => 'benetti'],
                'model' => ['name' => 'Oasis 40M', 'slug' => null],
                'year_built' => 2021,
                'loa_m' => 40.8,
            ],
            'listing' => [
                'name' => 'OASIS',
                'summary' => 'A fine yacht.',
                'price' => ['amount' => '8500000', 'currency' => 'EUR'],
                'location' => ['display' => 'Palma de Mallorca, Spain'],
            ],
            'media' => [
                'profile' => ['url' => 'https://media.partner.example/profile.jpg', 'sha256' => null, 'caption' => 'Profile'],
                'gallery' => [
                    ['url' => 'https://media.partner.example/01.jpg', 'sha256' => null, 'caption' => 'Aft deck', 'sort' => 1],
                ],
            ],
            'usage' => [
                'display' => true,
                'attribution_required' => true,
                'attribution_text' => 'Courtesy of Partner Brokerage',
                'expires_with_listing' => true,
            ],
        ], $payloadOverrides),
    ]);
}

test('importing a copy projects its payload into queryable columns', function () {
    Bus::fake();

    $copy = importableCopy();
    $yacht = app(ImportService::class)->import($copy);

    expect($yacht->name)->toBe('OASIS')
        ->and($yacht->builder_name)->toBe('Benetti')
        ->and($yacht->year_built)->toBe(2021)
        ->and($yacht->loa_m)->toBe(40.8)
        ->and($yacht->price_amount)->toBe('8500000.00')
        ->and($yacht->price_currency)->toBe('EUR')
        ->and($yacht->location_display)->toBe('Palma de Mallorca, Spain')
        ->and($yacht->attribution_text)->toBe('Courtesy of Partner Brokerage');

    Bus::assertDispatched(ImportYachtMedia::class);
});

test('the media job generates srcset renditions and a cropped profile hero', function () {
    Storage::fake('public');
    Http::fake([
        'media.partner.example/*' => Http::response(fakeJpegBytes(), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    Bus::fake();
    $yacht = app(ImportService::class)->import(importableCopy());
    (new ImportYachtMedia($yacht))->handle();

    $yacht->refresh()->load('media');

    expect($yacht->media)->toHaveCount(2)
        ->and($yacht->media_synced_at)->not->toBeNull();

    $profile = $yacht->profileMedia();
    $renditionKeys = array_keys($profile->renditions);

    expect($renditionKeys)->toContain('w480', 'w960', 'w1920', 'crop_480', 'crop_960', 'crop_1920');

    foreach ($profile->renditions as $rendition) {
        Storage::disk('public')->assertExists($rendition['path']);
    }

    expect($profile->renditions['crop_960']['width'])->toBe(960)
        ->and($profile->renditions['crop_960']['height'])->toBe(540);
});

test('small source images do not produce upscaled duplicate renditions', function () {
    Storage::fake('public');
    Http::fake([
        'media.partner.example/*' => Http::response(fakeJpegBytes(600, 400), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    Bus::fake();
    $yacht = app(ImportService::class)->import(importableCopy());
    (new ImportYachtMedia($yacht))->handle();

    $gallery = $yacht->refresh()->media()->where('kind', 'gallery')->first();

    expect(array_keys($gallery->renditions))->toBe(['w480', 'w600']);
});

test('a media file failing its published sha256 is rejected', function () {
    Storage::fake('public');
    Http::fake([
        'media.partner.example/*' => Http::response(fakeJpegBytes(), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    Bus::fake();
    $yacht = app(ImportService::class)->import(importableCopy([
        'media' => ['profile' => ['sha256' => str_repeat('0', 64)]],
    ]));
    (new ImportYachtMedia($yacht))->handle();

    expect($yacht->refresh()->media()->where('kind', 'profile')->exists())->toBeFalse()
        ->and($yacht->media()->where('kind', 'gallery')->exists())->toBeTrue();
})->group('FP-14');

test('a non-image response is rejected', function () {
    Storage::fake('public');
    Http::fake([
        'media.partner.example/*' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'text/html']),
    ]);

    Bus::fake();
    $yacht = app(ImportService::class)->import(importableCopy());
    (new ImportYachtMedia($yacht))->handle();

    expect($yacht->refresh()->media()->count())->toBe(0);
})->group('FP-14');

test('tombstoned and display-forbidden copies cannot be imported', function () {
    Bus::fake();

    $tombstoned = ListingCopy::factory()->tombstoned()->create();
    expect(fn () => app(ImportService::class)->import($tombstoned))
        ->toThrow(InvalidArgumentException::class);

    $noDisplay = importableCopy(['usage' => ['display' => false]]);
    expect(fn () => app(ImportService::class)->import($noDisplay))
        ->toThrow(InvalidArgumentException::class);
});

test('a synced tombstone removes the import and its cached media', function () {
    config(['openyacht.domain' => 'this-node.example']);
    FederationKey::factory()->create();
    Storage::fake('public');
    Bus::fake();

    $partner = FederationPartner::factory()->verified()->create(['domain' => 'openyacht.partner.example']);
    $copy = importableCopy();
    $copy->update(['federation_partner_id' => $partner->id, 'canonical_uri' => 'https://openyacht.partner.example/openyacht/v1/listings/uuid-1']);

    $yacht = app(ImportService::class)->import($copy);
    Storage::disk('public')->put("imported/{$yacht->id}/profile-0-480.webp", 'x');

    Http::fake([
        'openyacht.partner.example/*' => Http::response([
            'data' => [[
                'id' => 'https://openyacht.partner.example/openyacht/v1/listings/uuid-1',
                'tombstone' => true,
                'status' => 'withdrawn',
                'updated_at' => '2026-08-21T11:00:00Z',
            ]],
            'meta' => ['generated_at' => '2026-08-21T12:00:00Z'],
        ]),
    ]);

    app(SyncService::class)->sync($partner);

    expect(ImportedYacht::query()->count())->toBe(0);
    Storage::disk('public')->assertMissing("imported/{$yacht->id}/profile-0-480.webp");
})->group('ID-7', 'ID-10');

test('a synced update refreshes the projection', function () {
    config(['openyacht.domain' => 'this-node.example']);
    FederationKey::factory()->create();
    Bus::fake();

    $partner = FederationPartner::factory()->verified()->create(['domain' => 'openyacht.partner.example']);
    $copy = importableCopy();
    $copy->update(['federation_partner_id' => $partner->id, 'canonical_uri' => 'https://openyacht.partner.example/openyacht/v1/listings/uuid-1']);

    $yacht = app(ImportService::class)->import($copy);

    Http::fake([
        'openyacht.partner.example/*' => Http::response([
            'data' => [array_replace_recursive($copy->payload, [
                'status' => 'under_offer',
                'updated_at' => '2026-08-21T13:00:00Z',
                'listing' => ['price' => ['amount' => '7900000']],
            ])],
            'meta' => ['generated_at' => '2026-08-21T14:00:00Z'],
        ]),
    ]);

    app(SyncService::class)->sync($partner);

    expect($yacht->refresh()->price_amount)->toBe('7900000.00')
        ->and($yacht->status)->toBe(ListingStatus::UnderOffer);
})->group('ID-7');

test('a media job for a removed import cleans up instead of failing', function () {
    Storage::fake('public');
    Http::fake([
        'media.partner.example/*' => Http::response(fakeJpegBytes(600, 400), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    Bus::fake();
    $yacht = app(ImportService::class)->import(importableCopy());
    $job = new ImportYachtMedia($yacht);

    expect($job->deleteWhenMissingModels)->toBeTrue();

    $yachtId = $yacht->id;
    $yacht->delete();

    $job->handle();

    expect(ImportedYacht::query()->find($yachtId))->toBeNull();
    Storage::disk('public')->assertDirectoryEmpty("imported/{$yachtId}");
});

test('importing requires the listings permission', function () {
    $this->seed(RoleSeeder::class);
    Bus::fake();

    $copy = importableCopy();

    $viewer = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Viewer));
    $editor = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Editor));

    $this->actingAs($viewer)
        ->post(route('imported-yachts.store', $copy))
        ->assertForbidden();

    $this->actingAs($editor)
        ->post(route('imported-yachts.store', $copy))
        ->assertRedirect();

    expect(ImportedYacht::query()->count())->toBe(1);
});

test('sale and charter imports are never mixed in one list', function () {
    $this->seed(RoleSeeder::class);

    ImportedYacht::factory()->create(['name' => 'SALE IMPORT']);
    ImportedYacht::factory()->create(['name' => 'CHARTER IMPORT', 'type' => 'charter']);

    $editor = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Editor));

    $names = fn (string $routeName): array => collect(
        $this->actingAs($editor)
            ->get(route($routeName))
            ->original->getData()['page']['props']['yachts']['data'],
    )->pluck('name')->all();

    expect($names('imported-yachts.index'))->toBe(['SALE IMPORT'])
        ->and($names('imported-charter-yachts.index'))->toBe(['CHARTER IMPORT']);
});

test('the imported list pages by 24 and filters by the partner behind the copy', function () {
    $this->seed(RoleSeeder::class);

    $alpha = FederationPartner::factory()->verified()->create(['node_name' => 'Alpha Yachts']);
    $beta = FederationPartner::factory()->verified()->create(['node_name' => 'Beta Yachts']);
    FederationPartner::factory()->verified()->create(['node_name' => 'Synced Only']);

    foreach (range(1, 24) as $index) {
        ImportedYacht::factory()
            ->for(ListingCopy::factory()->for($alpha, 'partner'), 'copy')
            ->create(['name' => sprintf('ALPHA %02d', $index)]);
    }

    ImportedYacht::factory()
        ->for(ListingCopy::factory()->for($beta, 'partner'), 'copy')
        ->create(['name' => 'BETA ONE']);

    $editor = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Editor));

    $props = fn (array $params = []): array => $this->actingAs($editor)
        ->get(route('imported-yachts.index', $params))
        ->assertOk()
        ->original->getData()['page']['props'];

    $first = $props();

    // Ordered by name, so the 24 alphas fill page one and BETA ONE is
    // alone on page two.
    expect($first['yachts']['total'])->toBe(25)
        ->and(collect($first['yachts']['data'])->pluck('name')->last())->toBe('ALPHA 24')
        ->and(collect($props(['page' => 2])['yachts']['data'])->pluck('name')->all())->toBe(['BETA ONE'])
        // Only partners with an import of this type are offered.
        ->and(collect($first['partners'])->pluck('label')->all())->toBe(['Alpha Yachts', 'Beta Yachts']);

    $filtered = $props(['partner' => $beta->id]);

    expect(collect($filtered['yachts']['data'])->pluck('name')->all())->toBe(['BETA ONE'])
        ->and($filtered['yachts']['total'])->toBe(1)
        ->and($filtered['filters']['partner'])->toBe((string) $beta->id);
});

test('a media import is not queued twice while one is pending', function () {
    Bus::fake();
    $yacht = ImportedYacht::factory()->create();

    ImportYachtMedia::dispatch($yacht);
    ImportYachtMedia::dispatch($yacht);

    Bus::assertDispatchedTimes(ImportYachtMedia::class, 1);
});

test('the media sweep re-queues yachts whose media never synced or drifted from the copy', function () {
    Bus::fake();

    $neverSynced = ImportedYacht::factory()->create([
        'listing_copy_id' => importableCopy()->id,
        'media_synced_at' => null,
    ]);

    $current = ImportedYacht::factory()->create([
        'listing_copy_id' => importableCopy()->id,
        'media_synced_at' => now(),
    ]);
    ImportedMedia::factory()->create(['imported_yacht_id' => $current->id, 'kind' => 'profile', 'source_url' => 'https://media.partner.example/profile.jpg', 'sort' => 0]);
    ImportedMedia::factory()->create(['imported_yacht_id' => $current->id, 'kind' => 'gallery', 'source_url' => 'https://media.partner.example/01.jpg', 'sort' => 1]);

    $drifted = ImportedYacht::factory()->create([
        'listing_copy_id' => importableCopy()->id,
        'media_synced_at' => now(),
    ]);
    ImportedMedia::factory()->create(['imported_yacht_id' => $drifted->id, 'kind' => 'profile', 'source_url' => 'https://media.partner.example/replaced-profile.jpg', 'sort' => 0]);

    $this->artisan('openyacht:sync-media')
        ->expectsOutputToContain('2 imported yachts')
        ->assertSuccessful();

    Bus::assertDispatched(ImportYachtMedia::class, fn (ImportYachtMedia $job) => $job->yacht->is($neverSynced));
    Bus::assertDispatched(ImportYachtMedia::class, fn (ImportYachtMedia $job) => $job->yacht->is($drifted));
    Bus::assertNotDispatched(ImportYachtMedia::class, fn (ImportYachtMedia $job) => $job->yacht->is($current));
});
