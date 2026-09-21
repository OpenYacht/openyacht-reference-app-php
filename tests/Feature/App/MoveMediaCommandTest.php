<?php

use App\Enums\ListingStatus;
use App\Models\SaleYacht;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('own listing media moves to the target disk, rows repoint, and served listings are re-announced', function () {
    Storage::fake('public');
    Storage::fake('s3');

    $active = SaleYacht::factory()->active()->create();
    $active->addMedia(UploadedFile::fake()->image('profile.jpg', 1600, 900))->toMediaCollection('profile');

    $sold = SaleYacht::factory()->create(['status' => ListingStatus::Sold]);
    $sold->addMedia(UploadedFile::fake()->image('01.jpg', 800, 600))->toMediaCollection('gallery');

    // The model stamps on every save; backdate underneath it so the
    // command's stamp is observable.
    SaleYacht::query()->update(['federation_updated_at' => now()->subDay()]);

    $original = $active->getFirstMedia('profile');
    $originalPath = $original->getPathRelativeToRoot();
    $thumbnailPath = $original->getPathRelativeToRoot('thumbnail');

    Storage::disk('public')->assertExists([$originalPath, $thumbnailPath]);
    Storage::disk('s3')->assertMissing($originalPath);
    $sha256 = hash('sha256', (string) Storage::disk('public')->get($originalPath));

    $this->artisan('openyacht:move-media', ['disk' => 's3', '--prune' => true])
        ->expectsOutputToContain('2 media files moved to [s3]; 1 listing re-announced.')
        ->assertSuccessful();

    Storage::disk('s3')->assertExists([$originalPath, $thumbnailPath]);
    Storage::disk('public')->assertMissing([$originalPath, $thumbnailPath]);

    expect($original->refresh())
        ->disk->toBe('s3')
        ->conversions_disk->toBe('s3');
    expect($sold->getFirstMedia('gallery')->disk)->toBe('s3');

    // The original bytes are untouched, so the published sha256 still holds.
    expect(hash('sha256', (string) Storage::disk('s3')->get($originalPath)))->toBe($sha256);

    expect($active->refresh()->federation_updated_at->isAfter(now()->subMinute()))->toBeTrue()
        ->and($sold->refresh()->federation_updated_at->isBefore(now()->subHours(23)))->toBeTrue();
})->group('LS-8', 'ID-7');

test('a second run finds nothing left to move', function () {
    Storage::fake('public');
    Storage::fake('s3');

    $yacht = SaleYacht::factory()->active()->create();
    $yacht->addMedia(UploadedFile::fake()->image('profile.jpg', 1600, 900))->toMediaCollection('profile');

    $this->artisan('openyacht:move-media', ['disk' => 's3'])->assertSuccessful();

    $this->artisan('openyacht:move-media', ['disk' => 's3'])
        ->expectsOutputToContain('0 media files moved to [s3]; 0 listings re-announced.')
        ->assertSuccessful();
});

test('an unconfigured disk is refused before anything moves', function () {
    Storage::fake('public');

    $yacht = SaleYacht::factory()->active()->create();
    $yacht->addMedia(UploadedFile::fake()->image('profile.jpg', 1600, 900))->toMediaCollection('profile');

    $this->artisan('openyacht:move-media', ['disk' => 'nowhere'])
        ->expectsOutputToContain('Disk [nowhere] is not configured')
        ->assertFailed();

    expect($yacht->getFirstMedia('profile')->disk)->toBe('public');
});
