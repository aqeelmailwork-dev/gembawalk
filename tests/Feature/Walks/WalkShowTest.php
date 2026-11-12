<?php

namespace Tests\Feature\Walks;

use App\Enums\ActionStatus;
use App\Enums\Role;
use App\Enums\WalkStatus;
use App\Models\Finding;
use App\Models\GembaWalk;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WalkShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::Admin->value);

        return $user;
    }

    // -- Walk page layout ----------------------------------------------------

    public function test_walk_page_has_no_free_form_photo_capture(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        Volt::actingAs($owner)->test('walks.show', ['walk' => $walk])
            ->assertSee($walk->title)
            ->assertDontSee('Capture what you see')
            ->assertDontSee('wire:model="capture"', false)
            ->assertDontSee('wire:model="gallery"', false);

        Volt::actingAs($this->admin())->test('walks.show', ['walk' => $walk])
            ->assertOk()
            ->assertSee($walk->title);
    }

    // -- Action status toggle ------------------------------------------------

    public function test_owner_can_toggle_action_on_a_completed_walk(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->completed()->for($owner)->create();
        $finding = Finding::factory()->for($walk, 'walk')->create(['action_status' => ActionStatus::Open->value]);

        $this->actingAs($owner);

        Volt::test('walks.show', ['walk' => $walk])
            ->call('toggleAction', $finding->id)
            ->assertHasNoErrors();

        $this->assertSame(ActionStatus::Done, $finding->refresh()->action_status);
    }

    public function test_admin_can_toggle_action(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();
        $finding = Finding::factory()->for($walk, 'walk')->create(['action_status' => ActionStatus::Open->value]);

        $this->actingAs($admin);

        Volt::test('walks.show', ['walk' => $walk])
            ->call('toggleAction', $finding->id)
            ->assertHasNoErrors();

        $this->assertSame(ActionStatus::Done, $finding->refresh()->action_status);
    }

    public function test_plain_user_is_denied_toggling_another_walks_action(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();
        $finding = Finding::factory()->for($walk, 'walk')->create();

        // A plain user can neither view the walk nor flip its action status, so
        // the 403 is expressed here as the updateAction gate returning false.
        $this->assertFalse($other->can('updateAction', $finding));
    }

    public function test_toggle_action_on_a_finding_from_another_walk_returns_404(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();
        $otherWalk = GembaWalk::factory()->for($owner)->create();
        $otherFinding = Finding::factory()->for($otherWalk, 'walk')->create();

        $this->actingAs($owner);

        Volt::test('walks.show', ['walk' => $walk])
            ->call('toggleAction', $otherFinding->id)
            ->assertNotFound();
    }

    // -- Edit finding --------------------------------------------------------

    public function test_edit_finding_and_save_finding_saves_all_fields(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create(['walked_on' => '2026-01-15']);
        $finding = Finding::factory()->for($walk, 'walk')->create(['observation' => 'Original']);

        $this->actingAs($owner);

        Volt::test('walks.show', ['walk' => $walk])
            ->call('editFinding', $finding->id)
            ->set('category', 'safety')
            ->set('severity', 'high')
            ->set('observation', 'New observation')
            ->set('actionRequired', 'Fix it now')
            ->set('actionOwner', 'Alice')
            ->set('dueOn', '2026-01-20')
            ->call('saveFinding')
            ->assertHasNoErrors();

        $finding->refresh();

        $this->assertSame('safety', $finding->category->value);
        $this->assertSame('high', $finding->severity->value);
        $this->assertSame('New observation', $finding->observation);
        $this->assertSame('Fix it now', $finding->action_required);
        $this->assertSame('Alice', $finding->action_owner);
        $this->assertSame('2026-01-20', $finding->due_on->format('Y-m-d'));
    }

    public function test_observation_is_required_when_saving(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();
        $finding = Finding::factory()->for($walk, 'walk')->create(['observation' => 'Original']);

        $this->actingAs($owner);

        Volt::test('walks.show', ['walk' => $walk])
            ->call('editFinding', $finding->id)
            ->set('observation', '')
            ->call('saveFinding')
            ->assertHasErrors('observation');

        $this->assertSame('Original', $finding->refresh()->observation);
    }

    public function test_due_on_before_walked_on_is_rejected(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create(['walked_on' => '2026-01-15']);
        $finding = Finding::factory()->for($walk, 'walk')->create();

        $this->actingAs($owner);

        Volt::test('walks.show', ['walk' => $walk])
            ->call('editFinding', $finding->id)
            ->set('dueOn', '2026-01-14')
            ->call('saveFinding')
            ->assertHasErrors('dueOn');
    }

    public function test_editing_a_finding_of_another_walk_returns_404(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();
        $otherWalk = GembaWalk::factory()->for($owner)->create();
        $otherFinding = Finding::factory()->for($otherWalk, 'walk')->create();

        $this->actingAs($owner);

        Volt::test('walks.show', ['walk' => $walk])
            ->call('editFinding', $otherFinding->id)
            ->assertNotFound();
    }

    public function test_other_user_editing_returns_403(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();
        $finding = Finding::factory()->for($walk, 'walk')->create();

        $this->actingAs($admin);

        Volt::test('walks.show', ['walk' => $walk])
            ->call('editFinding', $finding->id)
            ->assertForbidden();
    }

    // -- Replace photo -------------------------------------------------------

    public function test_replacement_swaps_photo_files(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();
        $finding = Finding::factory()->for($walk, 'walk')->withPhoto()->create();

        $oldPhoto = $finding->photo_path;

        Storage::disk('public')->assertExists($oldPhoto);

        $this->actingAs($owner);

        Volt::test('walks.show', ['walk' => $walk])
            ->call('editFinding', $finding->id)
            ->set('replacement', UploadedFile::fake()->image('new.jpg', 800, 600))
            ->assertHasNoErrors();

        $finding->refresh();

        Storage::disk('public')->assertMissing($oldPhoto);
        Storage::disk('public')->assertExists($finding->photo_path);
        Storage::disk('public')->assertExists($finding->thumbnail_path);

        $this->assertNotSame($oldPhoto, $finding->photo_path);
    }

    // -- Delete finding ------------------------------------------------------

    public function test_delete_finding_removes_files_and_renumbers_positions(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $first = Finding::factory()->for($walk, 'walk')->withPhoto()->create(['position' => 1]);
        Finding::factory()->for($walk, 'walk')->create(['position' => 2]);
        Finding::factory()->for($walk, 'walk')->create(['position' => 3]);

        $photoPath = $first->photo_path;

        Storage::disk('public')->assertExists($photoPath);

        $this->actingAs($owner);

        Volt::test('walks.show', ['walk' => $walk])
            ->call('confirmDelete', $first->id)
            ->call('deleteFinding')
            ->assertHasNoErrors();

        $this->assertSame(0, Finding::where('id', $first->id)->count());
        Storage::disk('public')->assertMissing($photoPath);

        $this->assertSame([1, 2], $walk->findings()->get()->pluck('position')->all());
    }

    // -- Complete / reopen ---------------------------------------------------

    public function test_complete_walk_blocked_when_finding_lacks_observation(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();
        Finding::factory()->for($walk, 'walk')->create(['observation' => null]);

        $this->actingAs($owner);

        Volt::test('walks.show', ['walk' => $walk])
            ->call('completeWalk')
            ->assertHasErrors('completionSummary');

        $this->assertSame(WalkStatus::Draft, $walk->refresh()->status);
    }

    public function test_complete_walk_succeeds_when_all_described(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();
        Finding::factory()->for($walk, 'walk')->create(['observation' => 'Described finding']);

        $this->actingAs($owner);

        Volt::test('walks.show', ['walk' => $walk])
            ->set('completionSummary', 'Great walk overall.')
            ->call('completeWalk')
            ->assertHasNoErrors();

        $walk->refresh();

        $this->assertSame(WalkStatus::Completed, $walk->status);
        $this->assertSame('Great walk overall.', $walk->summary);
    }

    public function test_edit_finding_after_completion_returns_403(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->completed()->for($owner)->create();
        $finding = Finding::factory()->for($walk, 'walk')->create();

        $this->actingAs($owner);

        Volt::test('walks.show', ['walk' => $walk])
            ->call('editFinding', $finding->id)
            ->assertForbidden();
    }

    public function test_reopen_walk_by_owner_works(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->completed()->for($owner)->create();

        $this->actingAs($owner);

        Volt::test('walks.show', ['walk' => $walk])
            ->call('reopenWalk')
            ->assertHasNoErrors();

        $this->assertSame(WalkStatus::Draft, $walk->refresh()->status);
        $this->assertNull($walk->refresh()->completed_at);
    }

    public function test_reopen_walk_by_admin_returns_403(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->completed()->for($owner)->create();

        $this->actingAs($admin);

        Volt::test('walks.show', ['walk' => $walk])
            ->call('reopenWalk')
            ->assertForbidden();
    }
}
