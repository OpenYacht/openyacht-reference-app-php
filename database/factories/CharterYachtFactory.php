<?php

namespace Database\Factories;

use App\Enums\ListingStatus;
use App\Models\CharterYacht;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The charter block defaults mirror the spec's charter-full.json example:
 * mixed-season rates in mixed currencies (a real-world shape consumers
 * must handle), registry operating-area slugs, and a crew list with one
 * TBA position. Money is digit strings, never floats (API-12).
 *
 * @extends Factory<CharterYacht>
 */
class CharterYachtFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vessel_id' => Vessel::factory(),
            'status' => ListingStatus::Draft,
            'name' => strtoupper(fake()->unique()->word()).' CHARTER TEST',
            'summary' => 'A clearly fictional test charter listing for federation development.',
            'condition' => 'used',
            'location_display' => 'Palma de Mallorca, Spain',
            'location_city' => 'Palma de Mallorca',
            'location_country' => 'ES',
            'location_marina' => 'Marina Ficticia',
            'location_lat' => 39.5696,
            'location_lon' => 2.6502,
            'specifications' => [
                'beam_m' => fake()->randomFloat(2, 4, 15),
                'draft_max_m' => fake()->randomFloat(2, 1, 4),
                'cruise_speed_kn' => fake()->numberBetween(10, 20),
                'max_speed_kn' => fake()->numberBetween(20, 30),
                'hull_material' => 'Fiberglass',
                'fuel_type' => 'diesel',
                'power_or_sail' => 'power',
                'cabins' => fake()->numberBetween(2, 6),
                'sleeps' => fake()->numberBetween(4, 12),
                'heads' => fake()->numberBetween(2, 6),
                'guests_cruising' => 12,
                'guests_entertaining' => fake()->numberBetween(20, 40),
            ],
            'descriptions' => [
                ['section' => 'overview', 'content' => '<p>A fictional charter yacht used to test the OpenYacht federation protocol.</p>'],
            ],
            'features' => [
                ['category' => 'comfort', 'name' => 'Air conditioning', 'slug' => 'air-conditioning'],
            ],
            'compliance' => [
                'not_for_sale_to_us_residents_in_us_waters' => false,
            ],
            'rates' => [
                [
                    'season' => 'summer',
                    'rate_type' => 'weekly',
                    'amount_min' => (string) (fake()->numberBetween(50, 150) * 1000),
                    'amount_max' => (string) (fake()->numberBetween(150, 200) * 1000),
                    'currency' => 'EUR',
                    'contract_terms' => 'MYBA',
                    'apa_percent' => 30,
                    'vat_percent' => null,
                    'valid_from' => '2026-05-01',
                    'valid_to' => '2026-09-30',
                ],
                [
                    'season' => 'winter',
                    'rate_type' => 'weekly',
                    'amount_min' => '110000',
                    'amount_max' => '110000',
                    'currency' => 'USD',
                    'contract_terms' => 'MYBA',
                    'apa_percent' => 30,
                    'vat_percent' => null,
                    'valid_from' => '2026-12-01',
                    'valid_to' => '2027-04-30',
                ],
            ],
            'operating_areas' => [
                ['name' => 'Western Mediterranean', 'slug' => 'western-mediterranean', 'season' => 'summer'],
                ['name' => 'Leeward Islands', 'slug' => 'leeward-islands', 'season' => 'winter'],
            ],
            'summer_base_port' => 'Palma de Mallorca',
            'winter_base_port' => 'Antigua',
            'crew' => [
                [
                    'role' => 'Captain',
                    'name' => fake()->name(),
                    'nationality' => 'British',
                    'bio' => 'A fictional captain invented for protocol testing.',
                    'photo_url' => null,
                    'tba' => false,
                ],
                [
                    'role' => 'Chef',
                    'name' => null,
                    'nationality' => null,
                    'bio' => null,
                    'photo_url' => null,
                    'tba' => true,
                ],
            ],
            'crew_attested_at' => now(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => ListingStatus::Active,
            'listed_at' => now(),
        ]);
    }

    public function terminal(ListingStatus $status = ListingStatus::Withdrawn): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'listed_at' => now()->subMonth(),
        ]);
    }

    public function unattested(): static
    {
        return $this->state(fn (): array => [
            'crew_attested_at' => null,
        ]);
    }
}
