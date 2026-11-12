<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChecklistCategory>
 */
class ChecklistCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Office', 'Warehouse', 'Packing', 'Assembly', 'Canteen', 'Loading Bay']),
            'description' => fake()->optional()->sentence(),
            'position' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the category is hidden from walks.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
