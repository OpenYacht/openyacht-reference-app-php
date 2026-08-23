<?php

namespace Database\Factories;

use App\Enums\ListingStatus;
use App\Models\ImportedYacht;
use App\Models\ListingCopy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportedYacht>
 */
class ImportedYachtFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'listing_copy_id' => ListingCopy::factory(),
            'name' => strtoupper(fake()->firstName()),
            'type' => 'sale',
            'status' => ListingStatus::Active,
            'builder_name' => fake()->company(),
            'year_built' => fake()->numberBetween(1990, 2026),
            'loa_m' => fake()->randomFloat(2, 10, 90),
            'price_amount' => (string) fake()->numberBetween(100000, 20000000),
            'price_currency' => 'USD',
            'location_display' => fake()->city(),
        ];
    }
}
