<?php

namespace Database\Factories;

use App\Models\FileDirectory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FileDirectory> */
class FileDirectoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'scope_key' => 'root',
            'name' => fake()->unique()->words(2, true),
        ];
    }

    public function childOf(FileDirectory $parent): static
    {
        return $this->state(fn (): array => [
            'parent_id' => $parent->getKey(),
            'scope_key' => 'parent:'.$parent->getKey(),
        ]);
    }
}
