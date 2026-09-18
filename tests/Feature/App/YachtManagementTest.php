<?php

use App\Enums\ListingStatus;
use App\Enums\Role;
use App\Models\FederationPartner;
use App\Models\SaleYacht;
use App\Models\User;
use App\Services\Federation\FeatureRegistry;
use App\Services\Federation\ListingSerializer;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['openyacht.domain' => 'this-node.example']);
    $this->seed(RoleSeeder::class);
});

function yachtActor(Role $role): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
}

test('an editor can create a yacht, which starts as a draft with a minted UUID', function () {
    $editor = yachtActor(Role::Editor);

    $this->actingAs($editor)
        ->post(route('yachts.store'), [
            'name' => 'FICTIONAL ONE',
            'builder_slug' => 'benetti',
            'year_built' => 2020,
            'loa_m' => 40.5,
            'price_amount' => '5000000',
            'price_currency' => 'EUR',
            'location_display' => 'Palma de Mallorca, Spain',
            'specifications' => [
                'power_or_sail' => 'power',
                'guests_cruising' => 12,
                'guests_entertaining' => 35,
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $yacht = SaleYacht::query()->firstOrFail();

    expect($yacht->status)->toBe(ListingStatus::Draft)
        ->and($yacht->specifications['guests_entertaining'])->toBe(35)
        ->and($yacht->uuid)->not->toBeNull()
        ->and($yacht->vessel->builder_name)->toBe('Benetti')
        ->and($yacht->priceHistory()->count())->toBe(1)
        ->and($yacht->assigned_broker_id)->toBe($editor->id);
});

test('descriptions are sanitised on save and self-links removed', function () {
    config(['openyacht.domain' => 'this-node.example']);

    $this->actingAs(yachtActor(Role::Editor))
        ->post(route('yachts.store'), [
            'name' => 'SANITISED',
            'builder_slug' => 'benetti',
            'descriptions' => [
                ['section' => 'overview', 'content' => '<p onclick="x()">Fine yacht.</p><script>alert(1)</script><p><a href="https://this-node.example/us">us</a> <a href="https://other.example/x">them</a></p>'],
            ],
            'specifications' => ['power_or_sail' => 'power'],
        ])
        ->assertSessionHasNoErrors();

    $content = SaleYacht::query()->firstOrFail()->descriptions[0]['content'];

    expect($content)->not->toContain('script')
        ->and($content)->not->toContain('onclick')
        ->and($content)->not->toContain('this-node.example')
        ->and($content)->toContain('https://other.example/x');
})->group('LS-4');

test('an invented category slug is rejected, a vocabulary one carries its canonical name', function () {
    $this->actingAs(yachtActor(Role::Editor))
        ->post(route('yachts.store'), [
            'name' => 'BAD CATEGORY',
            'builder_slug' => 'benetti',
            'specifications' => ['power_or_sail' => 'power', 'category' => ['name' => 'Invented', 'slug' => 'invented-category']],
        ])
        ->assertSessionHasErrors('specifications.category.slug');

    $this->actingAs(yachtActor(Role::Editor))
        ->post(route('yachts.store'), [
            'name' => 'GOOD CATEGORY',
            'builder_slug' => 'benetti',
            'specifications' => ['power_or_sail' => 'power', 'category' => ['name' => 'whatever', 'slug' => 'flybridge']],
        ])
        ->assertSessionHasNoErrors();

    expect(SaleYacht::query()->firstOrFail()->specifications['category'])
        ->toBe(['name' => 'Flybridge', 'slug' => 'flybridge']);
})->group('LS-6');

test('features store a count in quantity alone, null when unstated', function () {
    $this->actingAs(yachtActor(Role::Editor))
        ->post(route('yachts.store'), [
            'name' => 'BAD QUANTITY',
            'builder_slug' => 'benetti',
            'features' => [['category' => 'toys', 'name' => 'Seabob', 'slug' => 'seabob', 'quantity' => 0]],
            'specifications' => ['power_or_sail' => 'power'],
        ])
        ->assertSessionHasErrors('features.0.quantity');

    $this->actingAs(yachtActor(Role::Editor))
        ->post(route('yachts.store'), [
            'name' => 'COUNTED TOYS',
            'builder_slug' => 'benetti',
            'features' => [
                ['category' => 'toys', 'name' => 'Seabob', 'slug' => 'seabob', 'quantity' => '2'],
                ['category' => '', 'name' => 'Air conditioning', 'slug' => '', 'quantity' => ''],
            ],
            'specifications' => ['power_or_sail' => 'power'],
        ])
        ->assertSessionHasNoErrors();

    expect(SaleYacht::query()->firstOrFail()->features)->toEqual([
        ['category' => 'toys', 'name' => 'Seabob', 'slug' => 'seabob', 'quantity' => 2],
        ['category' => null, 'name' => 'Air conditioning', 'slug' => null, 'quantity' => null],
    ]);
})->group('LS-1');

test('a feature slug is a registry claim: invented ones are rejected, the name stays the broker\'s', function () {
    $this->actingAs(yachtActor(Role::Editor))
        ->post(route('yachts.store'), [
            'name' => 'INVENTED FEATURE',
            'builder_slug' => 'benetti',
            'features' => [['category' => 'toys', 'name' => 'Hoverboard', 'slug' => 'hoverboard-9000', 'quantity' => null]],
            'specifications' => ['power_or_sail' => 'power'],
        ])
        ->assertSessionHasErrors('features.0.slug');

    $this->actingAs(yachtActor(Role::Editor))
        ->post(route('yachts.store'), [
            'name' => 'LINKED FEATURES',
            'builder_slug' => 'benetti',
            'features' => [
                // Linked: the broker reworded the name and category after the pick.
                ['category' => 'toys', 'name' => 'Seabob F5 SR', 'slug' => 'seabob', 'quantity' => 2],
                // "No link" chosen on purpose, though the name matches an entry exactly.
                ['category' => 'comfort', 'name' => 'Air Conditioning', 'slug' => '', 'quantity' => null],
            ],
            'specifications' => ['power_or_sail' => 'power'],
        ])
        ->assertSessionHasNoErrors();

    expect(SaleYacht::query()->firstOrFail()->features)->toEqual([
        ['category' => 'toys', 'name' => 'Seabob F5 SR', 'slug' => 'seabob', 'quantity' => 2],
        ['category' => 'comfort', 'name' => 'Air Conditioning', 'slug' => null, 'quantity' => null],
    ]);
})->group('LS-6');

test('the listing forms receive the vendored feature vocabulary', function () {
    $editor = yachtActor(Role::Editor);
    $yacht = SaleYacht::factory()->create(['assigned_broker_id' => $editor->id]);
    $vocabulary = app(FeatureRegistry::class)->all();

    expect($vocabulary)->not->toBeEmpty()
        ->and($vocabulary[0])->toHaveKeys(['slug', 'name', 'category']);

    foreach ([route('yachts.create'), route('yachts.edit', $yacht)] as $url) {
        $this->actingAs($editor)->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('featureVocabulary', count($vocabulary))
                ->where('featureVocabulary.0', $vocabulary[0]));
    }
});

test('a category left blank is stored as null, never a nameless object', function () {
    $this->actingAs(yachtActor(Role::Editor))
        ->post(route('yachts.store'), [
            'name' => 'NO CATEGORY',
            'builder_slug' => 'benetti',
            'specifications' => ['power_or_sail' => 'power', 'category' => ['name' => '', 'slug' => '']],
        ])
        ->assertSessionHasNoErrors();

    expect(SaleYacht::query()->firstOrFail()->specifications['category'])->toBeNull();
})->group('LS-1');

test('an invented builder slug is rejected at data entry', function () {
    $this->actingAs(yachtActor(Role::Editor))
        ->post(route('yachts.store'), [
            'name' => 'BAD SLUG',
            'builder_slug' => 'not-a-registry-builder',
            'specifications' => ['power_or_sail' => 'power'],
        ])
        ->assertSessionHasErrors('builder_slug');
})->group('LS-11');

test('an unlisted builder is stored by name with a null slug', function () {
    $this->actingAs(yachtActor(Role::Editor))
        ->post(route('yachts.store'), [
            'name' => 'UNLISTED BUILDER',
            'builder_name' => 'Astilleros Ficticios',
            'specifications' => ['power_or_sail' => 'power'],
        ])
        ->assertSessionHasNoErrors();

    $yacht = SaleYacht::query()->firstOrFail();

    expect($yacht->vessel->builder_slug)->toBeNull()
        ->and($yacht->vessel->builder_name)->toBe('Astilleros Ficticios');
})->group('LS-11');

test('the map picker uses mapbox only when a token is configured', function () {
    // Independent of whatever provider the local .env selects.
    config(['openyacht.map.provider' => 'openstreetmap', 'openyacht.map.mapbox_token' => null]);

    $editor = yachtActor(Role::Editor);

    $mapConfig = fn (): array => $this->actingAs($editor)
        ->get(route('yachts.create'))
        ->original->getData()['page']['props']['map'];

    expect($mapConfig())->toBe(['provider' => 'openstreetmap', 'mapbox_token' => null]);

    config(['openyacht.map.provider' => 'mapbox']);
    expect($mapConfig())->toBe(['provider' => 'openstreetmap', 'mapbox_token' => null]);

    config(['openyacht.map.mapbox_token' => 'pk.test-token']);
    expect($mapConfig())->toBe(['provider' => 'mapbox', 'mapbox_token' => 'pk.test-token']);
});

test('the yacht list can be searched and filtered', function () {
    $editor = yachtActor(Role::Editor);

    $blue = SaleYacht::factory()->active()->create([
        'name' => 'BLUE HORIZON',
        'location_display' => 'Palma de Mallorca, Spain',
        'specifications' => ['power_or_sail' => 'power', 'category' => ['name' => 'Flybridge', 'slug' => 'flybridge']],
    ]);
    $blue->vessel->update(['loa_m' => 42.0, 'builder_name' => 'Benetti', 'year_built' => 2020]);

    $sea = SaleYacht::factory()->create([
        'name' => 'SEA DREAM',
        'location_display' => 'Antibes, France',
        'location_city' => 'Antibes',
        'location_country' => 'FR',
        'location_marina' => null,
        'specifications' => ['power_or_sail' => 'sail'],
    ]);
    $sea->vessel->update(['loa_m' => 18.5, 'builder_name' => 'Nautor Swan', 'year_built' => 1998]);

    $names = fn (array $params): array => collect(
        $this->actingAs($editor)
            ->get(route('yachts.index', $params))
            ->original->getData()['page']['props']['yachts'],
    )->pluck('name')->all();

    expect($names(['q' => 'horizon']))->toBe(['BLUE HORIZON'])
        ->and($names([]))->toHaveCount(2)
        ->and($names(['q' => 'no-such-yacht']))->toBe([])
        ->and($names(['location' => 'palma']))->toBe(['BLUE HORIZON'])
        ->and($names(['category' => 'flybridge']))->toBe(['BLUE HORIZON'])
        ->and($names(['loa_min' => 30]))->toBe(['BLUE HORIZON'])
        ->and($names(['loa_max' => 30]))->toBe(['SEA DREAM'])
        ->and($names(['loa_min' => 10, 'loa_max' => 50]))->toHaveCount(2)
        ->and($names(['builder' => 'Benetti']))->toBe(['BLUE HORIZON'])
        ->and($names(['year_min' => 2000]))->toBe(['BLUE HORIZON'])
        ->and($names(['year_max' => 2000]))->toBe(['SEA DREAM'])
        // power_or_sail is a string in the JSON — this would catch a
        // numeric-affinity regression in the comparison.
        ->and($names(['power_sail' => 'sail']))->toBe(['SEA DREAM'])
        ->and($names(['status' => 'active']))->toBe(['BLUE HORIZON'])
        // An unknown status is ignored, not an error.
        ->and($names(['status' => 'bogus']))->toHaveCount(2);
});

test('brokers see and edit only their own listings', function () {
    $broker = yachtActor(Role::Broker);
    $own = SaleYacht::factory()->create(['assigned_broker_id' => $broker->id]);
    $other = SaleYacht::factory()->create();

    $response = $this->actingAs($broker)->get(route('yachts.index'))->assertOk();

    $ids = collect($response->original->getData()['page']['props']['yachts'])->pluck('id');

    expect($ids->all())->toBe([$own->id]);

    $this->actingAs($broker)
        ->get(route('yachts.edit', $other))
        ->assertForbidden();

    $this->actingAs($broker)
        ->get(route('yachts.edit', $own))
        ->assertOk();
});

test('viewers cannot access yacht management', function () {
    $this->actingAs(yachtActor(Role::Viewer))
        ->get(route('yachts.index'))
        ->assertForbidden();
});

test('the transition endpoint follows the lifecycle', function () {
    $editor = yachtActor(Role::Editor);
    $yacht = SaleYacht::factory()->create();

    $this->actingAs($editor)
        ->post(route('yachts.transition', $yacht), ['status' => 'active'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($yacht->refresh()->status)->toBe(ListingStatus::Active);

    $this->actingAs($editor)
        ->post(route('yachts.transition', $yacht), ['status' => 'draft'])
        ->assertSessionHasErrors('status');
})->group('ID-8');

test('uploaded media carries a content hash and the profile serves a thumbnail', function () {
    Storage::fake('public');

    $editor = yachtActor(Role::Editor);
    $yacht = SaleYacht::factory()->active()->create();

    $this->actingAs($editor)
        ->post(route('yachts.media.store', $yacht), [
            'collection' => 'profile',
            'file' => UploadedFile::fake()->image('profile.jpg', 1600, 900),
            'caption' => 'Profile shot',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $partner = FederationPartner::factory()->verified()->create();
    $media = app(ListingSerializer::class)->serialize($yacht->refresh(), $partner)['media'];

    expect($media['profile'])->not->toBeNull()
        ->and($media['profile']['sha256'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($media['profile']['width'])->toBe(1600)
        ->and($media['profile']['height'])->toBe(900)
        ->and($media['profile']['thumbnail_url'])->toContain('thumbnail')
        ->and($media['profile']['caption'])->toBe('Profile shot');
})->group('LS-8');

test('gallery items serve a nullable thumbnail that is a rendition of the same image', function () {
    Storage::fake('public');

    $editor = yachtActor(Role::Editor);
    $yacht = SaleYacht::factory()->active()->create();

    $this->actingAs($editor)
        ->post(route('yachts.media.store', $yacht), [
            'collection' => 'gallery',
            'file' => UploadedFile::fake()->image('01.jpg', 1600, 900),
            'caption' => 'Aft deck',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $partner = FederationPartner::factory()->verified()->create();
    $serializer = app(ListingSerializer::class);
    $item = $serializer->serialize($yacht->refresh(), $partner)['media']['gallery'][0];
    $media = $yacht->getMedia('gallery')->first();

    // LS-16: the thumbnail is a conversion of the gallery file itself — a
    // rendition of the same image, never a different photograph.
    expect($item['thumbnail_url'])->toBe($media->getFullUrl('thumbnail'))
        ->and($item['thumbnail_url'])->not->toBe($item['url']);

    // A gallery image whose conversion has not been generated (media that
    // predates the thumbnail migration) serves null — no small rendition,
    // consumers derive from url — never a dead URL.
    $media->update(['generated_conversions' => []]);

    $item = $serializer->serialize($yacht->refresh(), $partner)['media']['gallery'][0];

    expect($item['thumbnail_url'])->toBeNull();
})->group('LS-16');

test('layouts, documents, videos, and tours serialise per the media schema', function () {
    Storage::fake('public');

    $editor = yachtActor(Role::Editor);
    $yacht = SaleYacht::factory()->active()->create([
        'videos' => [['url' => 'https://vimeo.com/12345', 'caption' => 'Walkthrough']],
        'tours' => [['url' => 'https://my.matterport.com/show/abc', 'caption' => null]],
    ]);

    $this->actingAs($editor)
        ->post(route('yachts.media.store', $yacht), [
            'collection' => 'layouts',
            'file' => UploadedFile::fake()->image('ga.jpg', 1600, 900),
            'caption' => 'General arrangement',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($editor)
        ->post(route('yachts.media.store', $yacht), [
            'collection' => 'documents',
            'file' => UploadedFile::fake()->create('brochure.pdf', 100, 'application/pdf'),
            'caption' => 'Brochure',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $serializer = app(ListingSerializer::class);
    $partner = FederationPartner::factory()->verified()->create();
    $media = $serializer->serialize($yacht->refresh(), $partner)['media'];

    // Layouts mirror gallery items (nullable thumbnail, LS-16); videos
    // and tours are external links with sha256 null; documents carry the
    // content hash.
    expect($media['layouts'][0]['caption'])->toBe('General arrangement')
        ->and($media['layouts'][0]['thumbnail_url'])->toContain('thumbnail')
        ->and($media['videos'][0])->toBe(['url' => 'https://vimeo.com/12345', 'sha256' => null, 'caption' => 'Walkthrough', 'sort' => 1])
        ->and($media['tours'][0])->toBe(['url' => 'https://my.matterport.com/show/abc', 'caption' => null, 'sort' => 1])
        ->and($media['documents'][0]['caption'])->toBe('Brochure')
        ->and($media['documents'][0]['sha256'])->toMatch('/^[0-9a-f]{64}$/');

    // Documents sit under the documents field group: withheld, the list
    // serves as [] — a URL you may not use is worse than no entry.
    $partner->update(['field_groups' => []]);
    $gated = $serializer->serialize($yacht->refresh(), $partner->refresh())['media'];

    expect($gated['documents'])->toBe([])
        ->and($gated['layouts'])->not->toBe([]);
})->group('LS-14', 'LS-16');

test('deleting media requires ownership of the yacht', function () {
    Storage::fake('public');

    $editor = yachtActor(Role::Editor);
    $yacht = SaleYacht::factory()->create();

    $this->actingAs($editor)->post(route('yachts.media.store', $yacht), [
        'collection' => 'gallery',
        'file' => UploadedFile::fake()->image('01.jpg', 800, 600),
    ]);

    $media = $yacht->getMedia('gallery')->first();
    $otherYacht = SaleYacht::factory()->create();

    $this->actingAs($editor)
        ->delete(route('yachts.media.destroy', [$otherYacht, $media]))
        ->assertNotFound();

    $this->actingAs($editor)
        ->delete(route('yachts.media.destroy', [$yacht, $media]))
        ->assertRedirect();

    expect($yacht->refresh()->getMedia('gallery'))->toHaveCount(0);
});
