<?php

namespace Database\Seeders;

use App\Models\ChecklistCategory;
use Illuminate\Database\Seeder;

/**
 * Example checklist so a new install has something to walk with. Only runs when
 * no categories exist, so it never overwrites what an admin has set up.
 */
class ChecklistSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (ChecklistCategory::exists()) {
            return;
        }

        $examples = [
            'Office' => ['Desk area clean?', 'Aisles and walkways clear?', 'Bins emptied?', 'Lights working?'],
            'Warehouse' => ['Pallets stored in marked zones?', 'Fire exits unobstructed?', 'Racking labels in place?'],
            'Safety' => ['First aid kit stocked?', 'Fire extinguishers inspected?', 'PPE worn in required areas?'],
        ];

        $categoryPosition = 1;

        foreach ($examples as $name => $questions) {
            $category = ChecklistCategory::create(['name' => $name, 'position' => $categoryPosition++]);

            foreach ($questions as $index => $question) {
                $category->items()->create(['question' => $question, 'position' => $index + 1]);
            }
        }
    }
}
