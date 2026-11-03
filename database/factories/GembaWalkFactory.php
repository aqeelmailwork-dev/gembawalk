<?php

namespace Database\Factories;

use App\Enums\WalkStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GembaWalk>
 */
class GembaWalkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->randomElement([
                'Line 3 morning walk',
                'Warehouse safety sweep',
                'Packing line afternoon walk',
                'Assembly Line 2 first shift walk',
                'Shipping dock gemba walk',
                'Paint shop standard work check',
            ]),
            'area' => fake()->randomElement([
                'Assembly Line 3',
                'Warehouse',
                'Packing',
                'Assembly Line 2',
                'Shipping Dock',
                'Paint Shop',
            ]),
            'walked_on' => now()->subDays(fake()->numberBetween(0, 29))->toDateString(),
            'status' => WalkStatus::Draft->value,
            'summary' => null,
            'completed_at' => null,
        ];
    }

    /**
     * Indicate that the walk has been completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WalkStatus::Completed->value,
            'completed_at' => now(),
        ]);
    }
}
