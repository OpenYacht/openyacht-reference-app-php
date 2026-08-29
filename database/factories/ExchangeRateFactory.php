<?php

namespace Database\Factories;

use App\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'currency' => fake()->unique()->randomElement(['USD', 'GBP', 'CHF', 'AUD', 'CAD', 'JPY', 'SEK', 'NOK', 'DKK']),
            'rate' => fake()->randomFloat(6, 0.5, 2),
            'published_at' => now()->toDateString(),
        ];
    }

    public function usd(float $rate = 1.10): static
    {
        return $this->state(['currency' => 'USD', 'rate' => $rate]);
    }

    public function gbp(float $rate = 0.85): static
    {
        return $this->state(['currency' => 'GBP', 'rate' => $rate]);
    }
}
