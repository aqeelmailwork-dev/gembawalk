<?php

namespace Tests\Feature\Walks;

use App\Enums\Role;
use App\Enums\WalkStatus;
use App\Enums\YesNo;
use App\Models\ChecklistAnswer;
use App\Models\ChecklistCategory;
use App\Models\ChecklistItem;
use App\Models\Finding;
use App\Models\GembaWalk;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WalkChecklistTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private GembaWalk $walk;

    private ChecklistCategory $office;

    private ChecklistItem $clean;

    private ChecklistItem $bins;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::User->value);
        $this->walk = GembaWalk::factory()->for($this->owner)->create();

        $this->office = ChecklistCategory::factory()->create(['name' => 'Office', 'position' => 1]);
        $this->clean = ChecklistItem::factory()->for($this->office, 'category')->create(['question' => 'Desk area clean?', 'position' => 1]);
        $this->bins = ChecklistItem::factory()->for($this->office, 'category')->create(['question' => 'Bins emptied?', 'position' => 2]);
    }

    private function asOwner()
    {
        return Volt::actingAs($this->owner)->test('walks.checklist', ['walk' => $this->walk]);
    }

    private function answerFor(ChecklistItem $item): ?ChecklistAnswer
    {
        return $this->walk->checklistAnswers()->where('checklist_item_id', $item->id)->first();
    }

    public function test_walk_page_shows_active_categories_and_checks_only(): void
    {
        $hiddenCategory = ChecklistCategory::factory()->inactive()->create(['name' => 'Old canteen']);
        ChecklistItem::factory()->for($hiddenCategory, 'category')->create(['question' => 'Fridge cleaned?']);
        ChecklistItem::factory()->for($this->office, 'category')->inactive()->create(['question' => 'Retired check?']);

        $this->asOwner()
            ->call('toggleCategory', $this->office->id)
            ->assertSee('Office')
            ->assertSee('Desk area clean?')
            ->assertSee('0 of 2 checked')
            ->assertDontSee('Old canteen')
            ->assertDontSee('Retired check?');
    }

    public function test_owner_answers_yes_and_no_and_tapping_again_clears(): void
    {
        $component = $this->asOwner()->call('answer', $this->clean->id, 'yes');

        $answer = $this->answerFor($this->clean);
        $this->assertSame(YesNo::Yes, $answer->answer);
        $this->assertSame('Office', $answer->category_name);
        $this->assertSame('Desk area clean?', $answer->question);
        $this->assertSame($this->office->id, $answer->checklist_category_id);

        $component->call('answer', $this->clean->id, 'no');
        $this->assertSame(YesNo::No, $this->answerFor($this->clean)->answer);
        $this->assertSame(1, $this->walk->checklistAnswers()->count());

        $component->call('answer', $this->clean->id, 'no');
        $this->assertNull($this->answerFor($this->clean));
    }

    public function test_invalid_answers_and_hidden_checks_are_rejected(): void
    {
        $this->asOwner()->call('answer', $this->clean->id, 'maybe')->assertStatus(422);

        $this->clean->update(['is_active' => false]);
        $this->asOwner()->call('answer', $this->clean->id, 'yes')->assertNotFound();

        $this->office->update(['is_active' => false]);
        $this->asOwner()->call('answer', $this->bins->id, 'yes')->assertNotFound();

        $this->assertSame(0, ChecklistAnswer::count());
    }

    public function test_only_the_owner_of_a_draft_can_answer(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        Volt::actingAs($admin)->test('walks.checklist', ['walk' => $this->walk])
            ->call('answer', $this->clean->id, 'yes')
            ->assertForbidden();

        $stranger = User::factory()->create();
        $stranger->assignRole(Role::User->value);
        Volt::actingAs($stranger)->test('walks.checklist', ['walk' => $this->walk])->assertForbidden();

        $this->walk->forceFill(['status' => WalkStatus::Completed, 'completed_at' => now()])->save();
        $this->asOwner()->call('answer', $this->clean->id, 'yes')->assertForbidden();

        $this->assertSame(0, ChecklistAnswer::count());
    }

    public function test_note_is_saved_on_a_no_answer(): void
    {
        $this->asOwner()
            ->call('answer', $this->bins->id, 'no')
            ->set("notes.{$this->bins->id}", 'Overflowing by the printer');

        $this->assertSame('Overflowing by the printer', $this->answerFor($this->bins)->note);
    }

    public function test_photo_on_a_no_answer_becomes_a_linked_finding_and_can_be_replaced(): void
    {
        $component = $this->asOwner()
            ->call('answer', $this->bins->id, 'no')
            ->set("notes.{$this->bins->id}", 'Overflowing')
            ->set("photos.{$this->bins->id}", UploadedFile::fake()->image('bins.jpg', 1200, 900))
            ->assertHasNoErrors()
            ->assertDispatched('finding-added');

        $answer = $this->answerFor($this->bins);
        $finding = $answer->finding;

        $this->assertNotNull($finding);
        $this->assertSame($this->walk->id, $finding->gemba_walk_id);
        $this->assertSame('Office: Bins emptied? - No. Overflowing', $finding->observation);
        Storage::disk('public')->assertExists($finding->photo_path);

        $oldPhoto = $finding->photo_path;

        $component->set("photos.{$this->bins->id}", UploadedFile::fake()->image('bins2.jpg', 1200, 900));

        $this->assertSame(1, Finding::count());
        Storage::disk('public')->assertMissing($oldPhoto);
        Storage::disk('public')->assertExists($finding->fresh()->photo_path);
    }

    public function test_photo_is_refused_for_a_yes_answer_and_for_non_images(): void
    {
        $this->asOwner()
            ->call('answer', $this->clean->id, 'yes')
            ->set("photos.{$this->clean->id}", UploadedFile::fake()->image('ok.jpg'))
            ->assertStatus(422);

        $this->asOwner()
            ->call('answer', $this->bins->id, 'no')
            ->set("photos.{$this->bins->id}", UploadedFile::fake()->create('photo.heic', 100, 'image/heic'))
            ->assertHasErrors("photos.{$this->bins->id}");

        $this->assertSame(0, Finding::count());
    }

    public function test_deleting_the_linked_finding_keeps_the_answer(): void
    {
        $this->asOwner()
            ->call('answer', $this->bins->id, 'no')
            ->set("photos.{$this->bins->id}", UploadedFile::fake()->image('bins.jpg'));

        $this->answerFor($this->bins)->finding->delete();

        $answer = $this->answerFor($this->bins);
        $this->assertNull($answer->finding_id);
        $this->assertSame(YesNo::No, $answer->answer);
    }

    public function test_admin_sees_recorded_answers_read_only_even_after_checks_change(): void
    {
        $this->asOwner()
            ->call('answer', $this->clean->id, 'yes')
            ->call('answer', $this->bins->id, 'no')
            ->set("notes.{$this->bins->id}", 'Overflowing');

        $this->bins->update(['question' => 'Recycling bins emptied?']);

        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        Volt::actingAs($admin)->test('walks.checklist', ['walk' => $this->walk])
            ->assertSee('Bins emptied?')
            ->assertDontSee('Recycling bins emptied?')
            ->assertSee('Overflowing')
            ->assertSee('2 of 2 checked')
            ->assertDontSee('wire:click="answer(', false);
    }

    public function test_walk_page_embeds_the_checklist(): void
    {
        $this->actingAs($this->owner)
            ->get(route('walks.show', $this->walk))
            ->assertOk()
            ->assertSeeLivewire('walks.checklist');
    }
}
