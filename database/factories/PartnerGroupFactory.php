<?php

namespace Database\Factories;

use App\Models\PartnerGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerGroup>
 */
class PartnerGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
