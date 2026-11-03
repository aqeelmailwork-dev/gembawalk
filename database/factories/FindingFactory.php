<?php

namespace Database\Factories;

use App\Enums\ActionStatus;
use App\Enums\FindingCategory;
use App\Enums\Severity;
use App\Models\Finding;
use App\Models\GembaWalk;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\UploadedFile;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Finding>
 */
class FindingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gemba_walk_id' => GembaWalk::factory(),
            'photo_path' => null,
            'thumbnail_path' => null,
            'category' => fake()->randomElement(FindingCategory::cases())->value,
            'severity' => fake()->randomElement(Severity::cases())->value,
            'observation' => fake()->randomElement([
                'Pallet leaning against the rack near the east aisle.',
                'Small coolant spill at station four, not yet cleaned.',
                'Missing floor marking tape around the staging area.',
                'Shadow board has several missing hand tools.',
                'Operator reaching over a running conveyor to adjust parts.',
                'Label printer jamming repeatedly during changeover.',
            ]),
            'action_required' => null,
            'action_owner' => null,
            'due_on' => null,
            'action_status' => ActionStatus::Open->value,
            'position' => 0,
        ];
    }

    /**
     * Write a tiny fake jpg to the public disk and attach it to the finding.
     *
     * We use create() rather than image() because image() depends on the GD
     * extension, which is not guaranteed to be installed.
     */
    public function withPhoto(): static
    {
        return $this->afterCreating(function (Finding $finding) {
            $file = UploadedFile::fake()->create('finding.jpg', 10, 'image/jpeg');

            $path = $file->store("findings/{$finding->gemba_walk_id}", ['disk' => 'public']);

            $finding->photo_path = $path;
            $finding->thumbnail_path = $path;
            $finding->save();
        });
    }

    /**
     * Indicate that the open action is already overdue.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'action_status' => ActionStatus::Open->value,
            'due_on' => now()->subDay()->toDateString(),
        ]);
    }

    /**
     * Indicate that the action has been completed.
     */
    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'action_status' => ActionStatus::Done->value,
        ]);
    }
}
