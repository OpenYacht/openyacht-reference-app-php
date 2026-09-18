<?php

use App\Enums\ListingStatus;
use App\Enums\Role;
use App\Models\CharterYacht;
use App\Models\FederationKey;
use App\Models\FederationPartner;
use App\Models\SaleYacht;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/*
 * Every document this node emits, validated against the published JSON
 * Schemas vendored in resources/schemas/v1 — byte-identical copies of the
 * protocol repository's schemas/v1. The behavioural tests assert what a
 * response means; this file asserts that it is well-formed, which is what
 * catches a field the spec gained by amendment and the serializer never
 * adopted (LS-1).
 *
 * The scheduled schema-drift workflow runs this same file against the
 * schemas as currently published, by pointing OPENYACHT_SCHEMAS_PATH at a
 * fresh checkout — so an amendment upstream is reported as the concrete
 * failures it would cause here.
 */

beforeEach(function () {
    config([
        'openyacht.domain' => 'this-node.example',
        'openyacht.node_uuid' => '018f3c2e-0000-7000-8000-000000000001',
        'openyacht.node_name' => 'Example Node',
        'openyacht.website' => 'https://example.test',
    ]);

    FederationKey::factory()->create();

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

/**
 * Schema violations in a response body, as "path: message" lines — empty
 * when the document conforms.
 *
 * @return list<string>
 */
function schemaViolations(string $schema, TestResponse $response): array
{
    $validator = new Validator;
    $validator->setMaxErrors(20);
    $validator->resolver()?->registerPrefix(
        'https://openyacht.org/schemas/v1/',
        getenv('OPENYACHT_SCHEMAS_PATH') ?: resource_path('schemas/v1'),
    );

    $result = $validator->validate(
        json_decode((string) $response->getContent(), flags: JSON_THROW_ON_ERROR),
        "https://openyacht.org/schemas/v1/{$schema}.schema.json",
    );

    if ($result->isValid()) {
        return [];
    }

    $violations = [];

    foreach ((new ErrorFormatter)->format($result->error()) as $path => $messages) {
        foreach ($messages as $message) {
            $violations[] = "{$path}: {$message}";
        }
    }

    return $violations;
}

function conformanceGet(object $test, string $pathWithQuery): TestResponse
{
    return $test->get('https://this-node.example'.$pathWithQuery, federationSignedHeaders(
        $test->partner->domain,
        $test->partnerKeypair['key_id'],
        $test->partnerKeypair['secret_key'],
        'GET',
        $pathWithQuery,
        'this-node.example',
    ));
}

test('the well-known document conforms to its schema', function () {
    $response = $this->get('https://this-node.example/.well-known/openyacht')->assertOk();

    expect(schemaViolations('well-known', $response))->toBe([]);
})->group('FP-1');

test('the capabilities document conforms to its schema', function () {
    $response = $this->get('https://this-node.example/openyacht/v1/capabilities')->assertOk();

    expect(schemaViolations('capabilities', $response))->toBe([]);
})->group('API-7');

test('an error response conforms to the error schema', function () {
    $response = $this->get('https://this-node.example/openyacht/v1/listings')->assertUnauthorized();

    expect(schemaViolations('error', $response))->toBe([]);
})->group('API-9');

test('sale and charter listings conform to the listing schema, fully shared and fully withheld', function (?array $fieldGroups) {
    $this->partner->update(['field_groups' => $fieldGroups]);

    $sale = SaleYacht::factory()->active()->create([
        'features' => [
            ['category' => 'toys', 'name' => 'Seabob', 'slug' => 'seabob', 'quantity' => 2],
            ['category' => null, 'name' => 'Fictional tender', 'slug' => null, 'quantity' => null],
        ],
    ]);
    $charter = CharterYacht::factory()->active()->create();

    foreach ([$sale, $charter] as $yacht) {
        $response = conformanceGet($this, '/openyacht/v1/listings/'.$yacht->uuid)->assertOk();

        expect(schemaViolations('listing', $response))->toBe([]);
    }
})->with([
    'every field group shared' => [null],
    'every field group withheld' => [[]],
])->group('LS-1', 'LS-14');

test('a listing with no optional data at all still conforms', function () {
    $yacht = SaleYacht::factory()->active()->create([
        'summary' => null,
        'location_display' => null,
        'location_city' => null,
        'location_state' => null,
        'location_country' => null,
        'location_marina' => null,
        'location_lat' => null,
        'location_lon' => null,
        'specifications' => ['power_or_sail' => 'power', 'category' => ['name' => null, 'slug' => null]],
        'descriptions' => null,
        'features' => null,
        'compliance' => null,
    ]);

    $response = conformanceGet($this, '/openyacht/v1/listings/'.$yacht->uuid)->assertOk();

    expect(schemaViolations('listing', $response))->toBe([]);
})->group('LS-1');

test('a listing carrying media conforms to the listing schema', function () {
    Storage::fake('public');
    $this->seed(RoleSeeder::class);

    $editor = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Editor));
    $yacht = SaleYacht::factory()->active()->create([
        'videos' => [['url' => 'https://video.example/watch/1', 'caption' => 'Walkthrough']],
        'tours' => [['url' => 'https://tours.example/t/1', 'caption' => null]],
    ]);

    foreach (['profile' => 'profile.jpg', 'gallery' => '01.jpg', 'layouts' => 'ga.jpg'] as $collection => $file) {
        $this->actingAs($editor)
            ->post(route('yachts.media.store', $yacht), [
                'collection' => $collection,
                'file' => UploadedFile::fake()->image($file, 1600, 900),
            ])
            ->assertSessionHasNoErrors();
    }

    $response = conformanceGet($this, '/openyacht/v1/listings/'.$yacht->uuid)->assertOk();

    expect($response->json('media.profile'))->not->toBeNull()
        ->and($response->json('media.gallery'))->toHaveCount(1)
        ->and(schemaViolations('listing', $response))->toBe([]);
})->group('LS-8', 'LS-16');

test('a feed page mixing listings and tombstones conforms to the collection schema', function () {
    SaleYacht::factory()->active()->create();
    CharterYacht::factory()->active()->create();
    SaleYacht::factory()->terminal(ListingStatus::Sold)->create();

    $response = conformanceGet($this, '/openyacht/v1/listings?updated_since=2020-01-01T00:00:00Z')->assertOk();

    expect(collect($response->json('data'))->pluck('tombstone')->filter())->toHaveCount(1)
        ->and(schemaViolations('collection', $response))->toBe([]);
})->group('API-3');

test('the vendored schemas are the ones the validator resolves', function () {
    $response = conformanceGet($this, '/openyacht/v1/listings/'.SaleYacht::factory()->active()->create()->uuid);
    $broken = json_decode((string) $response->getContent(), true);
    unset($broken['features']);

    // Guards the harness itself: a resolver that silently loaded nothing
    // would pass every test above.
    expect(schemaViolations('listing', TestResponse::fromBaseResponse(response()->json($broken))))
        ->not->toBe([]);
})->group('LS-1');
