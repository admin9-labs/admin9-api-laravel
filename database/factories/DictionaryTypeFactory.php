<?php

namespace Database\Factories;

use App\Models\DictionaryType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DictionaryType>
 */
class DictionaryTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'code' => fake()->unique()->bothify('type_####'),
            'description' => fake()->optional()->sentence(),
            'sort' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
