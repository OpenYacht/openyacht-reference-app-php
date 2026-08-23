<?php

namespace Database\Factories;

use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Clearly fictional vessels: registry builders, invented boats — never
 * real-boat data.
 *
 * @extends Factory<Vessel>
 */
class VesselFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hin' => null,
            'imo' => null,
            'mmsi' => null,
            'official_number' => null,
            'builder_name' => 'Benetti',
            'builder_slug' => 'benetti',
            'model_name' => 'Fictional '.fake()->numberBetween(30, 90).'M',
            'model_slug' => null,
            'year_built' => fake()->numberBetween(1995, 2026),
            'refit_year' => null,
            'loa_m' => fake()->randomFloat(2, 12, 90),
            'previous_names' => [],
        ];
    }

    public function withIdentifiers(): static
    {
        return $this->state(fn (): array => [
            'hin' => 'ZZ-TEST'.fake()->numerify('#####').'A#'.fake()->numberBetween(10, 26),
            'mmsi' => (string) fake()->numberBetween(200000000, 799999999),
        ]);
    }
}
