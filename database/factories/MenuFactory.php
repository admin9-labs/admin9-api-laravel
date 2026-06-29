<?php

namespace Database\Factories;

use App\Models\Menu;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Menu>
 */
class MenuFactory extends Factory
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
            'code' => fake()->unique()->slug(),
            'path' => '/'.fake()->slug(),
            'component' => fake()->slug().'/index',
            'icon' => null,
            'type' => Menu::TYPE_PAGE,
            'permission_name' => null,
            'sort' => fake()->numberBetween(0, 100),
            'is_visible' => true,
            'is_active' => true,
        ];
    }

    public function directory(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Menu::TYPE_DIRECTORY,
            'component' => 'Layout',
        ]);
    }

    public function page(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Menu::TYPE_PAGE,
        ]);
    }

    public function button(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Menu::TYPE_BUTTON,
            'path' => null,
            'component' => null,
            'is_visible' => false,
        ]);
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_visible' => false,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
