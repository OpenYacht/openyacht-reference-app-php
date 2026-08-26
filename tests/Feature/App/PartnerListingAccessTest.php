<?php

use App\Enums\Role;
use App\Models\ImportedYacht;
use App\Models\ListingCopy;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function partnerListingActor(?Role $role = null): User
{
    return tap(User::factory()->create(), function (User $user) use ($role): void {
        if ($role !== null) {
            $user->assignRole($role);
        }
    });
}

test('partner listing pages require a listings permission', function (?Role $role, bool $allowed) {
    $actor = partnerListingActor($role);

    $this->actingAs($actor)
        ->get(route('synced-listings.index'))
        ->assertStatus($allowed ? 200 : 403);

    $this->actingAs($actor)
        ->get(route('imported-yachts.index'))
        ->assertStatus($allowed ? 200 : 403);
})->with([
    'no role' => [null, false],
    'viewer' => [Role::Viewer, false],
    'broker' => [Role::Broker, true],
    'editor' => [Role::Editor, true],
    'super admin' => [Role::SuperAdmin, true],
]);

test('a synced listing detail page renders from the stored payload with untrusted content filtered', function () {
    $copy = ListingCopy::factory()->create([
        'payload' => [
            'listing' => [
                'name' => 'TEST YACHT',
                'summary' => 'A fine yacht.',
                'location' => ['display' => 'Palma'],
            ],
            'vessel' => ['builder' => ['name' => 'Benetti'], 'year_built' => 2020, 'loa_m' => 30.5],
            'descriptions' => [
                ['section' => 'overview', 'content' => '<p>Hello <script>alert(1)</script>world</p>'],
            ],
            'media' => [
                'profile' => ['url' => 'http://insecure.example/hero.jpg'],
                'gallery' => [
                    ['url' => 'https://cdn.example/1.jpg', 'thumbnail_url' => 'https://cdn.example/1-thumb.jpg', 'caption' => 'Bow'],
                    ['url' => 'http://cdn.example/2.jpg', 'thumbnail_url' => 'https://cdn.example/2-thumb.jpg', 'caption' => 'Insecure'],
                ],
                'layouts' => [
                    ['url' => 'https://cdn.example/ga.jpg', 'thumbnail_url' => 'https://cdn.example/ga-thumb.jpg', 'caption' => 'GA'],
                ],
                'videos' => [
                    ['url' => 'https://vimeo.com/1', 'caption' => 'Walkthrough'],
                    ['url' => 'http://vimeo.com/2', 'caption' => 'Insecure'],
                ],
                'tours' => [
                    ['url' => 'https://tour.example/1', 'caption' => null],
                ],
                'documents' => [
                    ['url' => 'https://cdn.example/brochure.pdf', 'caption' => 'Brochure'],
                ],
            ],
            'usage' => ['display' => true],
        ],
    ]);
    $copy->partner->update(['node_name' => 'Partner Brokerage']);

    $this->actingAs(partnerListingActor(Role::Editor))
        ->get(route('synced-listings.show', $copy))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('federation/listings/Show')
            ->where('copy.node_name', 'Partner Brokerage')
            ->where('copy.builder_name', 'Benetti')
            ->where('copy.location_display', 'Palma')
            // Non-https media never renders (FP-14).
            ->where('copy.hero_url', null)
            ->has('copy.gallery', 1)
            ->where('copy.gallery.0.url', 'https://cdn.example/1.jpg')
            // The preview grid prefers the authority-served thumbnail (LS-16).
            ->where('copy.gallery.0.thumbnail_url', 'https://cdn.example/1-thumb.jpg')
            // The beyond-gallery lists render too — https-filtered the
            // same way (the insecure video is dropped).
            ->has('copy.remote_media.layouts', 1)
            ->where('copy.remote_media.layouts.0.thumbnail_url', 'https://cdn.example/ga-thumb.jpg')
            ->has('copy.remote_media.videos', 1)
            ->has('copy.remote_media.tours', 1)
            ->where('copy.remote_media.documents.0.caption', 'Brochure')
            // Descriptions are sanitised before rendering (LS-5).
            ->where('copy.descriptions.0.content', fn ($content): bool => str_contains((string) $content, 'Hello')
                && ! str_contains((string) $content, '<script')));
})->group('FP-14', 'LS-5');

test('the synced listing detail page requires a listings permission', function () {
    $copy = ListingCopy::factory()->create();

    $this->actingAs(partnerListingActor())
        ->get(route('synced-listings.show', $copy))
        ->assertForbidden();

    $this->actingAs(partnerListingActor(Role::Broker))
        ->get(route('synced-listings.show', $copy))
        ->assertOk();
});

test('an imported yacht detail page requires a listings permission', function () {
    $yacht = ImportedYacht::factory()->create();

    $this->actingAs(partnerListingActor())
        ->get(route('imported-yachts.show', $yacht))
        ->assertForbidden();

    $this->actingAs(partnerListingActor(Role::Editor))
        ->get(route('imported-yachts.show', $yacht))
        ->assertOk();
});
