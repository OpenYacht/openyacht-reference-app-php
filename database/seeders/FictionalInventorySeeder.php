<?php

namespace Database\Seeders;

use App\Enums\ListingStatus;
use App\Models\CharterYacht;
use App\Models\SaleYacht;
use App\Models\Vessel;
use Illuminate\Database\Seeder;
use Illuminate\Http\File;

/**
 * Clearly fictional test inventory for federation demos: registry
 * builders, invented vessels, generated imagery — never real-boat data.
 * Run explicitly with:
 *
 *   php artisan db:seed --class=FictionalInventorySeeder
 */
class FictionalInventorySeeder extends Seeder
{
    private const FLEET = [
        ['name' => 'TEST PATTERN', 'builder' => ['benetti', 'Benetti'], 'model' => 'Fictional 40M', 'year' => 2019, 'loa' => 40.2, 'price' => ['8500000', 'EUR'], 'location' => ['Palma de Mallorca, Spain', 'Palma de Mallorca', 'ES'], 'status' => ListingStatus::Active, 'color' => [31, 84, 147]],
        ['name' => 'NULL ISLAND', 'builder' => ['azimut', 'Azimut'], 'model' => 'Fictional 27M', 'year' => 2021, 'loa' => 27.6, 'price' => ['4750000', 'EUR'], 'location' => ['Antibes, France', 'Antibes', 'FR'], 'status' => ListingStatus::Active, 'color' => [24, 121, 108]],
        ['name' => 'PLACEHOLDER', 'builder' => ['sunseeker', 'Sunseeker'], 'model' => 'Fictional 88', 'year' => 2017, 'loa' => 26.8, 'price' => ['3200000', 'GBP'], 'location' => ['Poole, United Kingdom', 'Poole', 'GB'], 'status' => ListingStatus::Active, 'color' => [140, 62, 33]],
        ['name' => 'LOREM IPSUM', 'builder' => ['ferretti', 'Ferretti'], 'model' => 'Fictional 720', 'year' => 2022, 'loa' => 22.3, 'price' => ['2950000', 'EUR'], 'location' => ['Fort Lauderdale, United States', 'Fort Lauderdale', 'US'], 'status' => ListingStatus::Active, 'color' => [90, 44, 128]],
        ['name' => 'DRY RUN', 'builder' => ['princess', 'Princess'], 'model' => 'Fictional Y85', 'year' => 2020, 'loa' => 26.2, 'price' => ['4100000', 'USD'], 'location' => ['Newport, United States', 'Newport', 'US'], 'status' => ListingStatus::Active, 'color' => [163, 120, 24]],
        ['name' => 'SANDBOX', 'builder' => ['feadship', 'Feadship'], 'model' => 'Fictional 60M', 'year' => 2015, 'loa' => 60.4, 'price' => [null, null], 'location' => ['Monaco', 'Monaco', 'MC'], 'status' => ListingStatus::UnderOffer, 'color' => [40, 40, 60], 'poa' => true],
        ['name' => 'STAGING ONLY', 'builder' => ['beneteau', 'Beneteau'], 'model' => 'Fictional 62', 'year' => 2023, 'loa' => 18.9, 'price' => ['1450000', 'EUR'], 'location' => ['La Rochelle, France', 'La Rochelle', 'FR'], 'status' => ListingStatus::Draft, 'color' => [80, 100, 60]],
        ['name' => 'SOLD FIXTURE', 'builder' => ['nautor-swan', 'Nautor Swan'], 'model' => 'Fictional 78', 'year' => 2016, 'loa' => 23.9, 'price' => ['2100000', 'EUR'], 'location' => ['Helsinki, Finland', 'Helsinki', 'FI'], 'status' => ListingStatus::Sold, 'color' => [60, 60, 70]],
    ];

    /**
     * The charter side of the fictional fleet, mirroring the spec's
     * charter-full.json shapes: mixed-season rates in mixed currencies,
     * registry destination slugs plus one deliberately unlisted cruising
     * ground, and crew with a TBA position.
     */
    private const CHARTER_FLEET = [
        [
            'name' => 'WEEKLY BUILD', 'builder' => ['benetti', 'Benetti'], 'model' => 'Fictional 45M', 'year' => 2018, 'loa' => 45.0,
            'location' => ['Palma de Mallorca, Spain', 'Palma de Mallorca', 'ES'], 'status' => ListingStatus::Active, 'color' => [18, 99, 132],
            'rates' => [
                ['season' => 'summer', 'rate_type' => 'weekly', 'amount_min' => '125000', 'amount_max' => '135000', 'currency' => 'EUR', 'contract_terms' => 'MYBA', 'apa_percent' => 30, 'vat_percent' => null, 'valid_from' => '2026-05-01', 'valid_to' => '2026-09-30'],
                ['season' => 'winter', 'rate_type' => 'weekly', 'amount_min' => '110000', 'amount_max' => '110000', 'currency' => 'USD', 'contract_terms' => 'MYBA', 'apa_percent' => 30, 'vat_percent' => null, 'valid_from' => '2026-12-01', 'valid_to' => '2027-04-30'],
            ],
            'operating_areas' => [
                ['name' => 'Western Mediterranean', 'slug' => 'western-mediterranean', 'season' => 'summer'],
                ['name' => 'Leeward Islands', 'slug' => 'leeward-islands', 'season' => 'winter'],
            ],
            'summer_base_port' => 'Palma de Mallorca', 'winter_base_port' => 'Antigua',
            'crew' => [
                ['role' => 'Captain', 'name' => 'Fictional F. Skipper', 'nationality' => 'British', 'bio' => 'A fictional captain invented for protocol testing.', 'photo_url' => null, 'tba' => false],
                ['role' => 'Chef', 'name' => null, 'nationality' => null, 'bio' => null, 'photo_url' => null, 'tba' => true],
            ],
            'attested' => true,
        ],
        [
            'name' => 'RATE LIMIT', 'builder' => ['sunseeker', 'Sunseeker'], 'model' => 'Fictional 116', 'year' => 2021, 'loa' => 35.4,
            'location' => ['Athens, Greece', 'Athens', 'GR'], 'status' => ListingStatus::Active, 'color' => [126, 87, 27],
            'rates' => [
                ['season' => 'summer', 'rate_type' => 'weekly', 'amount_min' => '95000', 'amount_max' => '105000', 'currency' => 'EUR', 'contract_terms' => 'MYBA', 'apa_percent' => 25, 'vat_percent' => 13, 'valid_from' => '2026-04-15', 'valid_to' => '2026-10-15'],
            ],
            // An unlisted cruising ground: name only, slug null — the
            // registry escape hatch on the wire.
            'operating_areas' => [
                ['name' => 'Ionian Sea', 'slug' => null, 'season' => 'summer'],
            ],
            'summer_base_port' => 'Athens', 'winter_base_port' => null,
            // Crew entered but NOT attested — withheld from the wire
            // (LS-15) until the attestation is recorded.
            'crew' => [
                ['role' => 'Captain', 'name' => 'Placeholder P. Helm', 'nationality' => 'Greek', 'bio' => 'A fictional captain awaiting a distribution attestation.', 'photo_url' => null, 'tba' => false],
            ],
            'attested' => false,
        ],
        [
            'name' => 'OFF SEASON', 'builder' => ['feadship', 'Feadship'], 'model' => 'Fictional 55M', 'year' => 2014, 'loa' => 55.1,
            'location' => ['Monaco', 'Monaco', 'MC'], 'status' => ListingStatus::Draft, 'color' => [52, 52, 78],
            'rates' => [], 'operating_areas' => [], 'summer_base_port' => null, 'winter_base_port' => null, 'crew' => [], 'attested' => false,
        ],
    ];

    public function run(): void
    {
        $this->seedSaleFleet();
        $this->seedCharterFleet();
    }

    private function seedSaleFleet(): void
    {
        // Per-fleet guard: an install that seeded before charter support
        // existed can still pick up the charter fleet below.
        if (SaleYacht::query()->exists()) {
            $this->command->warn('Sale yachts already exist — skipping the fictional sale fleet.');

            return;
        }

        foreach (self::FLEET as $entry) {
            $vessel = Vessel::create([
                'builder_slug' => $entry['builder'][0],
                'builder_name' => $entry['builder'][1],
                'model_name' => $entry['model'],
                'year_built' => $entry['year'],
                'loa_m' => $entry['loa'],
                'hin' => 'ZZ-TEST'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT).'A8'.substr((string) $entry['year'], 2),
                'previous_names' => [],
            ]);

            $yacht = SaleYacht::create([
                'vessel_id' => $vessel->id,
                'name' => $entry['name'],
                'summary' => "Clearly fictional test listing — {$entry['model']} by {$entry['builder'][1]}. Exists only to exercise the OpenYacht federation protocol.",
                'condition' => 'used',
                'price_amount' => $entry['price'][0],
                'price_currency' => $entry['price'][1],
                'price_on_application' => $entry['poa'] ?? false,
                'location_display' => $entry['location'][0],
                'location_city' => $entry['location'][1],
                'location_country' => $entry['location'][2],
                'location_marina' => 'Marina Ficticia',
                'location_lat' => 39.5 + random_int(-300, 300) / 100,
                'location_lon' => 2.6 + random_int(-300, 300) / 100,
                'specifications' => [
                    'beam_m' => round($entry['loa'] / 4.8, 2),
                    'draft_max_m' => round($entry['loa'] / 14, 2),
                    'cruise_speed_kn' => random_int(10, 16),
                    'max_speed_kn' => random_int(17, 28),
                    'hull_material' => 'Fiberglass',
                    'fuel_type' => 'diesel',
                    'power_or_sail' => 'power',
                    'cabins' => random_int(3, 6),
                    'sleeps' => random_int(6, 12),
                    'heads' => random_int(3, 6),
                    'guests_cruising' => 12,
                    'guests_entertaining' => random_int(20, 40),
                    'engines' => [
                        ['make' => 'Fictional Marine', 'model' => 'FM-900', 'year' => $entry['year'], 'type' => 'inboard', 'drive_type' => 'Direct', 'power_hp' => 900, 'power_kw' => 671.13, 'fuel_type' => 'diesel', 'hours' => random_int(300, 2500), 'hours_recorded_at' => null, 'location' => 'port'],
                        ['make' => 'Fictional Marine', 'model' => 'FM-900', 'year' => $entry['year'], 'type' => 'inboard', 'drive_type' => 'Direct', 'power_hp' => 900, 'power_kw' => 671.13, 'fuel_type' => 'diesel', 'hours' => random_int(300, 2500), 'hours_recorded_at' => null, 'location' => 'starboard'],
                    ],
                ],
                'descriptions' => [
                    ['section' => 'overview', 'content' => "<p>{$entry['name']} is a fictional {$entry['model']} that exists only to test the OpenYacht federation protocol. Any resemblance to a real vessel is coincidental.</p>"],
                    ['section' => 'highlights', 'content' => '<ul><li>Invented for protocol testing</li><li>Registry-validated builder</li><li>No real-boat data</li></ul>'],
                ],
                'features' => [
                    ['category' => 'comfort', 'name' => 'Air conditioning', 'slug' => 'air-conditioning'],
                    ['category' => 'equipment', 'name' => 'Water maker', 'slug' => 'water-maker'],
                    ['category' => 'toys', 'name' => 'Fictional tender', 'slug' => null],
                ],
                'compliance' => ['not_for_sale_to_us_residents_in_us_waters' => false],
            ]);

            $this->attachGeneratedMedia($yacht, $entry['name'], $entry['color']);

            if ($entry['status'] !== ListingStatus::Draft) {
                $yacht->transitionTo(ListingStatus::Active);
            }

            if ($entry['status'] === ListingStatus::UnderOffer || $entry['status'] === ListingStatus::Sold) {
                $yacht->transitionTo($entry['status']);
            }

            $this->command->info("{$entry['name']} ({$entry['status']->value})");
        }
    }

    private function seedCharterFleet(): void
    {
        if (CharterYacht::query()->exists()) {
            $this->command->warn('Charter yachts already exist — skipping the fictional charter fleet.');

            return;
        }

        foreach (self::CHARTER_FLEET as $entry) {
            $vessel = Vessel::create([
                'builder_slug' => $entry['builder'][0],
                'builder_name' => $entry['builder'][1],
                'model_name' => $entry['model'],
                'year_built' => $entry['year'],
                'loa_m' => $entry['loa'],
                'hin' => 'ZZ-TEST'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT).'A8'.substr((string) $entry['year'], 2),
                'previous_names' => [],
            ]);

            $yacht = CharterYacht::create([
                'vessel_id' => $vessel->id,
                'name' => $entry['name'],
                'summary' => "Clearly fictional test charter listing — {$entry['model']} by {$entry['builder'][1]}. Exists only to exercise the OpenYacht federation protocol.",
                'condition' => 'used',
                'location_display' => $entry['location'][0],
                'location_city' => $entry['location'][1],
                'location_country' => $entry['location'][2],
                'location_marina' => 'Marina Ficticia',
                'location_lat' => 39.5 + random_int(-300, 300) / 100,
                'location_lon' => 2.6 + random_int(-300, 300) / 100,
                'specifications' => [
                    'beam_m' => round($entry['loa'] / 4.8, 2),
                    'draft_max_m' => round($entry['loa'] / 14, 2),
                    'cruise_speed_kn' => random_int(10, 16),
                    'max_speed_kn' => random_int(17, 28),
                    'hull_material' => 'Fiberglass',
                    'fuel_type' => 'diesel',
                    'power_or_sail' => 'power',
                    'cabins' => random_int(4, 6),
                    'sleeps' => random_int(8, 12),
                    'heads' => random_int(4, 7),
                    'guests_cruising' => 12,
                    'guests_entertaining' => random_int(20, 60),
                ],
                'descriptions' => [
                    ['section' => 'overview', 'content' => "<p>{$entry['name']} is a fictional {$entry['model']} charter yacht that exists only to test the OpenYacht federation protocol. Any resemblance to a real vessel is coincidental.</p>"],
                ],
                'features' => [
                    ['category' => 'comfort', 'name' => 'Air conditioning', 'slug' => 'air-conditioning'],
                    ['category' => 'toys', 'name' => 'Fictional tender', 'slug' => null],
                ],
                'compliance' => ['not_for_sale_to_us_residents_in_us_waters' => false],
                'rates' => $entry['rates'],
                'operating_areas' => $entry['operating_areas'],
                'summer_base_port' => $entry['summer_base_port'],
                'winter_base_port' => $entry['winter_base_port'],
                'crew' => $entry['crew'],
                'crew_attested_at' => $entry['attested'] ? now() : null,
            ]);

            $this->attachGeneratedMedia($yacht, $entry['name'], $entry['color']);

            if ($entry['status'] !== ListingStatus::Draft) {
                $yacht->transitionTo(ListingStatus::Active);
            }

            $this->command->info("{$entry['name']} (charter, {$entry['status']->value})");
        }
    }

    /**
     * Generated, obviously artificial imagery: a colour field with the
     * fictional name on it — for exercising the media pipeline, not for
     * pretending to be a photograph.
     *
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    private function attachGeneratedMedia(SaleYacht|CharterYacht $yacht, string $name, array $rgb): void
    {
        foreach (['profile' => $name, 'gallery' => $name.' — GALLERY 1', 'gallery-2' => $name.' — GALLERY 2'] as $key => $label) {
            $collection = $key === 'profile' ? 'profile' : 'gallery';
            $path = tempnam(sys_get_temp_dir(), 'oy').'.jpg';

            $this->generateImage($path, $label, $rgb, $key === 'profile' ? 0 : 25);

            $yacht->addMedia(new File($path))
                ->usingFileName(strtolower(str_replace(' ', '-', $label)).'.jpg')
                ->withCustomProperties([
                    'sha256' => hash_file('sha256', $path),
                    'width' => 1920,
                    'height' => 1080,
                    'caption' => $label,
                ])
                ->toMediaCollection($collection);
        }
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    private function generateImage(string $path, string $label, array $rgb, int $lighten): void
    {
        $image = imagecreatetruecolor(1920, 1080);

        $base = imagecolorallocate(
            $image,
            max(0, min($rgb[0] + $lighten, 255)),
            max(0, min($rgb[1] + $lighten, 255)),
            max(0, min($rgb[2] + $lighten, 255)),
        );
        imagefilledrectangle($image, 0, 0, 1920, 1080, (int) $base);

        // A simple horizon line so the images are visually distinct.
        $accent = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 720, 1920, 726, (int) $accent);

        $text = strtoupper($label.' · FICTIONAL TEST LISTING');
        imagestring($image, 5, (int) ((1920 - strlen($text) * 9) / 2), 520, $text, (int) $accent);

        imagejpeg($image, $path, 88);
        imagedestroy($image);
    }
}
