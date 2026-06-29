<?php

namespace Database\Factories;

use App\Models\DictionaryItem;
use App\Models\DictionaryType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DictionaryItem>
 */
class DictionaryItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dictionary_type_id' => DictionaryType::factory(),
            'name' => fake()->words(2, true),
            'code' => fake()->unique()->bothify('item_####'),
            'value' => fake()->word(),
            'description' => fake()->optional()->sentence(),
            'meta' => null,
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
