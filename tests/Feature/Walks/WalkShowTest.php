<?php

namespace Tests\Feature\Walks;

use App\Enums\ActionStatus;
use App\Enums\Role;
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

    // -- Photo capture -------------------------------------------------------

    public function test_owner_gallery_upload_creates_two_findings_with_positions_and_files(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->actingAs($owner);

        Volt::test('walks.show', ['walk' => $walk])
            ->set('gallery', [
                UploadedFile::fake()->image('a.jpg', 1200, 900),
                UploadedFile::fake()->image('b.jpg', 1200, 900),
            ])
            ->assertHasNoErrors();

        $this->assertSame(2, $walk->findings()->count());

        $findings = $walk->findings()->orderBy('position')->get();

        $this->assertSame([1, 2], $findings->pluck('position')->all());

        foreach ($findings as $finding) {
            Storage::disk('public')->assertExists($finding->photo_path);
            Storage::disk('public')->assertExists($finding->thumbnail_path);
        }
    }

    public function test_owner_capture_upload_creates_one_finding(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->actingAs($owner);

        Volt::test('walks.show', ['walk' => $walk])
            ->set('capture', UploadedFile::fake()->image('capture.jpg', 1200, 900))
            ->assertHasNoErrors();

        $this->assertSame(1, $walk->findings()->count());
    }

    public function test_heic_upload_gives_a_validation_error_and_no_finding(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->actingAs($owner);

        Volt::test('walks.show', ['walk' => $walk])
            ->set('capture', UploadedFile::fake()->create('x.heic', 100, 'image/heic'))
            ->assertHasErrors('capture');

        $this->assertSame(0, $walk->findings()->count());
    }

    public function test_13mb_image_is_rejected(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->actingAs($owner);

        Volt::test('walks.show', ['walk' => $walk])
            ->set('capture', UploadedFile::fake()->create('big.jpg', 13312, 'image/jpeg'))
            ->assertHasErrors('capture');

        $this->assertSame(0, $walk->findings()->count());
    }

    public function test_completed_walk_hides_capture_zone(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->completed()->for($owner)->create();

        $this->actingAs($owner);

        Volt::test('walks.show', ['walk' => $walk])
            ->assertDontSee('Capture what you see');
    }

    public function test_admin_sees_page_but_not_capture_zone_and_upload_is_forbidden(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->actingAs($admin);

        Volt::test('walks.show', ['walk' => $walk])
            ->assertSee($walk->title)
            ->assertDontSee('Capture what you see')
            ->set('capture', UploadedFile::fake()->image('admin.jpg', 100, 100))
            ->assertForbidden();

        $this->assertSame(0, $walk->findings()->count());
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
}
