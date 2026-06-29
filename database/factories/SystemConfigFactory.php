<?php

namespace Database\Factories;

use App\Models\SystemConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SystemConfig>
 */
class SystemConfigFactory extends Factory
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
            'key' => 'site.'.fake()->unique()->bothify('config_####'),
            'value' => fake()->word(),
            'type' => SystemConfig::TYPE_STRING,
            'config_group' => 'default',
            'description' => fake()->optional()->sentence(),
            'is_public' => false,
            'is_active' => true,
            'sort' => fake()->numberBetween(0, 100),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function public(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_public' => true,
        ]);
    }
}
