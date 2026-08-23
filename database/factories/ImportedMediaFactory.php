<?php

namespace Database\Factories;

use App\Models\ImportedMedia;
use App\Models\ImportedYacht;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportedMedia>
 */
class ImportedMediaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'imported_yacht_id' => ImportedYacht::factory(),
            'kind' => 'gallery',
            'source_url' => 'https://media.example/'.fake()->uuid().'.jpg',
            'source_sha256' => null,
            'sort' => 1,
            'renditions' => [
                '480' => ['path' => 'imported/1/gallery-1-480.webp', 'width' => 480, 'height' => 320],
            ],
        ];
    }
}
