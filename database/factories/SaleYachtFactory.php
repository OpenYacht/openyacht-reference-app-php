<?php

namespace Database\Factories;

use App\Enums\ListingStatus;
use App\Models\SaleYacht;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleYacht>
 */
class SaleYachtFactory extends Factory
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
            'name' => strtoupper(fake()->unique()->word()).' TEST',
            'summary' => 'A clearly fictional test listing for federation development.',
            'condition' => 'used',
            'price_amount' => (string) (fake()->numberBetween(200, 20000) * 1000),
            'price_currency' => fake()->randomElement(['EUR', 'USD']),
            'price_on_application' => false,
            'starting_price' => false,
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
                ['section' => 'overview', 'content' => '<p>A fictional yacht used to test the OpenYacht federation protocol.</p>'],
            ],
            'features' => [
                ['category' => 'comfort', 'name' => 'Air conditioning', 'slug' => 'air-conditioning', 'quantity' => null],
            ],
            'compliance' => [
                'not_for_sale_to_us_residents_in_us_waters' => false,
            ],
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
}
