<?php

namespace Database\Factories;

use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word().'.jpg',
            'disk' => 'public',
            'path' => 'media/'.now()->format('Y/m').'/'.fake()->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size' => fake()->numberBetween(1_000, 5_000_000),
            'width' => fake()->numberBetween(100, 2_000),
            'height' => fake()->numberBetween(100, 2_000),
            'status' => Media::STATUS_READY,
            'created_by' => null,
            'deletion_token' => null,
            'deletion_started_at' => null,
        ];
    }
}
