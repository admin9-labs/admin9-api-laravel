<?php

namespace Database\Factories;

use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word().'.pdf',
            'type' => 'document',
            'disk' => 'public',
            'path' => 'files/'.now()->format('Y/m').'/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => fake()->numberBetween(1_000, 5_000_000),
            'width' => null,
            'height' => null,
            'status' => File::STATUS_READY,
            'created_by' => null,
            'deletion_token' => null,
            'deletion_started_at' => null,
        ];
    }
}
