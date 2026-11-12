<?php

namespace Database\Factories;

use App\Models\ChecklistCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChecklistItem>
 */
class ChecklistItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'checklist_category_id' => ChecklistCategory::factory(),
            'question' => fake()->randomElement(['Area clean?', 'Aisles clear?', 'Bins emptied?', 'Lights working?', 'Labels in place?']),
            'position' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the check is hidden from walks.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
