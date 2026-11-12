<?php

namespace Tests\Feature\Walks;

use App\Enums\Role;
use App\Models\ChecklistAnswer;
use App\Models\ChecklistCategory;
use App\Models\ChecklistItem;
use App\Models\GembaWalk;
use App\Models\User;
use App\Support\WalkReport;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WalkReportChecklistTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private GembaWalk $walk;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::User->value);
        $this->walk = GembaWalk::factory()->for($this->owner)->create();
    }

    private function answer(ChecklistItem $item, string $value, ?string $note = null): ChecklistAnswer
    {
        $answer = new ChecklistAnswer(['answer' => $value, 'note' => $note]);
        $answer->gemba_walk_id = $this->walk->id;
        $answer->checklist_item_id = $item->id;
        $answer->checklist_category_id = $item->checklist_category_id;
        $answer->category_name = $item->category->name;
        $answer->question = $item->question;
        $answer->save();

        return $answer;
    }

    /**
     * Office (position 2): 2 yes, 1 no. Warehouse (position 1): 1 no.
     */
    private function seedAnswers(): void
    {
        $office = ChecklistCategory::factory()->create(['name' => 'Office', 'position' => 2]);
        $warehouse = ChecklistCategory::factory()->create(['name' => 'Warehouse', 'position' => 1]);

        $this->answer(ChecklistItem::factory()->for($office, 'category')->create(['question' => 'Desk clean?', 'position' => 1]), 'yes');
        $this->answer(ChecklistItem::factory()->for($office, 'category')->create(['question' => 'Bins emptied?', 'position' => 3]), 'no', 'Overflowing');
        $this->answer(ChecklistItem::factory()->for($office, 'category')->create(['question' => 'Lights on?', 'position' => 2]), 'yes');
        $this->answer(ChecklistItem::factory()->for($warehouse, 'category')->create(['question' => 'Exits clear?', 'position' => 1]), 'no');
    }

    public function test_scores_are_calculated_per_category_in_admin_order(): void
    {
        $this->seedAnswers();

        $report = new WalkReport($this->walk->fresh());

        $this->assertSame(['answered' => 4, 'yes' => 2, 'no' => 2, 'score' => 50], $report->checklistTotals());

        $groups = $report->checklistByCategory();
        $this->assertSame(['Warehouse', 'Office'], $groups->pluck('name')->all());
        $this->assertSame(0, $groups[0]['score']);
        $this->assertSame(67, $groups[1]['score']);
        $this->assertSame(['Desk clean?', 'Lights on?', 'Bins emptied?'], $groups[1]['answers']->pluck('question')->all());

        $this->assertSame(['Exits clear?', 'Bins emptied?'], $report->checklistFailures()->pluck('question')->all());
    }

    public function test_answers_for_deleted_checks_still_report(): void
    {
        $this->seedAnswers();

        ChecklistCategory::where('name', 'Warehouse')->delete();

        $groups = (new WalkReport($this->walk->fresh()))->checklistByCategory();

        $this->assertSame(['Office', 'Warehouse'], $groups->pluck('name')->all());
    }

    public function test_report_page_and_pdf_include_checklist_results(): void
    {
        $this->seedAnswers();

        $this->actingAs($this->owner)
            ->get(route('walks.report', $this->walk))
            ->assertOk()
            ->assertSee('Checklist results')
            ->assertSee('50%')
            ->assertSee('Needs attention')
            ->assertSee('Overflowing');

        $this->actingAs($this->owner)
            ->get(route('walks.report.pdf', $this->walk))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_report_hides_the_section_when_nothing_was_checked(): void
    {
        $this->actingAs($this->owner)
            ->get(route('walks.report', $this->walk))
            ->assertOk()
            ->assertDontSee('Checklist results');
    }
}
