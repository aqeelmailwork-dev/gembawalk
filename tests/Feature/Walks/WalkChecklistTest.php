<?php

namespace Tests\Feature\Walks;

use App\Enums\Role;
use App\Enums\WalkStatus;
use App\Enums\YesNo;
use App\Models\ChecklistAnswer;
use App\Models\ChecklistAnswerPhoto;
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

    /**
     * @return array<int, UploadedFile>
     */
    private function images(int $count): array
    {
        return array_map(fn ($i) => UploadedFile::fake()->image("photo{$i}.jpg", 1200, 900), range(1, $count));
    }

    public function test_several_photos_can_be_added_to_a_check_without_creating_findings(): void
    {
        $component = $this->asOwner()
            ->call('toggleCategory', $this->office->id)
            ->call('answer', $this->bins->id, 'no')
            ->set("photos.{$this->bins->id}", $this->images(3))
            ->assertHasNoErrors()
            ->assertSee('Add more');

        $photos = $this->answerFor($this->bins)->photos;

        $this->assertCount(3, $photos);
        $this->assertSame([1, 2, 3], $photos->pluck('position')->all());
        foreach ($photos as $photo) {
            Storage::disk('public')->assertExists([$photo->photo_path, $photo->thumbnail_path]);
        }

        $component->set("photos.{$this->bins->id}", $this->images(2));

        $this->assertSame([1, 2, 3, 4, 5], $this->answerFor($this->bins)->photos->pluck('position')->all());
        $this->assertSame(0, Finding::count());
    }

    public function test_yes_answers_can_have_photos_too(): void
    {
        $this->asOwner()
            ->call('answer', $this->clean->id, 'yes')
            ->set("photos.{$this->clean->id}", $this->images(2))
            ->assertHasNoErrors();

        $this->assertCount(2, $this->answerFor($this->clean)->photos);
    }

    public function test_photo_limit_per_check_is_enforced(): void
    {
        $component = $this->asOwner()
            ->call('toggleCategory', $this->office->id)
            ->call('answer', $this->bins->id, 'no')
            ->set("photos.{$this->bins->id}", $this->images(11))
            ->assertHasErrors("photos.{$this->bins->id}");

        $this->assertCount(0, $this->answerFor($this->bins)->photos);

        $component->set("photos.{$this->bins->id}", $this->images(8))->assertHasNoErrors();
        $component->set("photos.{$this->bins->id}", $this->images(3))
            ->assertHasErrors("photos.{$this->bins->id}")
            ->assertSee('You can add 2 more photos');

        $this->assertCount(8, $this->answerFor($this->bins)->photos);
    }

    public function test_photos_need_an_answer_and_must_be_images(): void
    {
        $this->asOwner()
            ->set("photos.{$this->bins->id}", $this->images(1))
            ->assertNotFound();

        $this->asOwner()
            ->call('answer', $this->bins->id, 'no')
            ->set("photos.{$this->bins->id}", [UploadedFile::fake()->create('photo.heic', 100, 'image/heic')])
            ->assertHasErrors("photos.{$this->bins->id}.0");

        $this->assertSame(0, ChecklistAnswerPhoto::count());
    }

    public function test_owner_can_remove_a_photo_and_its_files_go(): void
    {
        $component = $this->asOwner()
            ->call('answer', $this->bins->id, 'no')
            ->set("photos.{$this->bins->id}", $this->images(2));

        $photo = $this->answerFor($this->bins)->photos->first();

        $component->call('removePhoto', $photo->id);

        $this->assertModelMissing($photo);
        Storage::disk('public')->assertMissing([$photo->photo_path, $photo->thumbnail_path]);
        $this->assertCount(1, $this->answerFor($this->bins)->photos);
    }

    public function test_photos_from_another_walk_or_by_another_user_cannot_be_removed(): void
    {
        $this->asOwner()
            ->call('answer', $this->bins->id, 'no')
            ->set("photos.{$this->bins->id}", $this->images(1));
        $photo = $this->answerFor($this->bins)->photos->first();

        $otherWalk = GembaWalk::factory()->for($this->owner)->create();
        Volt::actingAs($this->owner)->test('walks.checklist', ['walk' => $otherWalk])
            ->call('removePhoto', $photo->id)
            ->assertNotFound();

        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);
        Volt::actingAs($admin)->test('walks.checklist', ['walk' => $this->walk])
            ->call('removePhoto', $photo->id)
            ->assertForbidden();

        $this->assertModelExists($photo);
    }

    public function test_clearing_an_answer_or_deleting_the_walk_removes_photo_files(): void
    {
        $component = $this->asOwner()
            ->call('answer', $this->bins->id, 'no')
            ->set("photos.{$this->bins->id}", $this->images(2))
            ->call('answer', $this->clean->id, 'yes')
            ->set("photos.{$this->clean->id}", $this->images(1));

        $binsPaths = $this->answerFor($this->bins)->photos->flatMap(fn ($p) => [$p->photo_path, $p->thumbnail_path])->all();
        $cleanPaths = $this->answerFor($this->clean)->photos->flatMap(fn ($p) => [$p->photo_path, $p->thumbnail_path])->all();

        // Switching No -> Yes keeps the photos; tapping the selected answer clears it.
        $component->call('answer', $this->bins->id, 'yes');
        $this->assertCount(2, $this->answerFor($this->bins)->photos);

        $component->call('answer', $this->bins->id, 'yes');
        $this->assertNull($this->answerFor($this->bins));
        Storage::disk('public')->assertMissing($binsPaths);

        $this->walk->delete();
        $this->assertSame(0, ChecklistAnswerPhoto::count());
        Storage::disk('public')->assertMissing($cleanPaths);
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
