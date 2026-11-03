<?php

namespace Tests\Feature\Walks;

use App\Enums\Role;
use App\Models\GembaWalk;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WalkPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::Admin->value);

        return $user;
    }

    // -- Access -------------------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/walks')->assertRedirect(route('login'));
    }

    public function test_pending_user_is_redirected_to_login(): void
    {
        $user = User::factory()->pending()->create();

        $this->actingAs($user)->get('/walks')->assertRedirect(route('login'));
    }

    // -- Index --------------------------------------------------------------

    public function test_user_sees_only_their_own_walks(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        GembaWalk::factory()->for($owner)->create(['title' => 'My own walk']);
        GembaWalk::factory()->for($other)->create(['title' => 'Someone else walk']);

        $this->actingAs($owner);

        Volt::test('walks.index')
            ->assertSee('My own walk')
            ->assertDontSee('Someone else walk');
    }

    public function test_admin_sees_all_walks_and_walked_by_filter_works(): void
    {
        $admin = $this->admin();
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();

        GembaWalk::factory()->for($ownerA)->create(['title' => 'Walk from A']);
        GembaWalk::factory()->for($ownerB)->create(['title' => 'Walk from B']);

        $this->actingAs($admin);

        Volt::test('walks.index')
            ->assertSee('Walk from A')
            ->assertSee('Walk from B')
            ->set('walkedBy', $ownerA->id)
            ->assertSee('Walk from A')
            ->assertDontSee('Walk from B');
    }

    public function test_search_filters_by_title_or_area(): void
    {
        $owner = User::factory()->create();

        GembaWalk::factory()->for($owner)->create(['title' => 'Line 3 morning walk', 'area' => 'Assembly Line 3']);
        GembaWalk::factory()->for($owner)->create(['title' => 'Warehouse sweep', 'area' => 'Warehouse']);

        $this->actingAs($owner);

        Volt::test('walks.index')
            ->set('search', 'Warehouse')
            ->assertSee('Warehouse sweep')
            ->assertDontSee('Line 3 morning walk');
    }

    public function test_status_filter_shows_only_matching_walks(): void
    {
        $owner = User::factory()->create();

        GembaWalk::factory()->for($owner)->create(['title' => 'Draft walk']);
        GembaWalk::factory()->for($owner)->completed()->create(['title' => 'Completed walk']);

        $this->actingAs($owner);

        Volt::test('walks.index')
            ->set('status', 'completed')
            ->assertSee('Completed walk')
            ->assertDontSee('Draft walk');
    }

    // -- Create -------------------------------------------------------------

    public function test_create_validates_required_fields_and_rejects_future_date(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner);

        Volt::test('walks.create')
            ->set('title', '')
            ->set('area', '')
            ->set('walked_on', now()->addDay()->toDateString())
            ->call('save')
            ->assertHasErrors(['title', 'area', 'walked_on']);

        $this->assertDatabaseCount('gemba_walks', 0);
    }

    public function test_create_creates_a_draft_owned_by_user_and_redirects_to_show(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner);

        $response = Volt::test('walks.create')
            ->set('title', 'Line 3 morning walk')
            ->set('area', 'Assembly Line 3')
            ->set('walked_on', now()->toDateString())
            ->call('save');

        $walk = GembaWalk::latest('id')->first();

        $response
            ->assertHasNoErrors()
            ->assertRedirectToRoute('walks.show', ['walk' => $walk->id]);

        $this->assertSame($owner->id, $walk->user_id);
        $this->assertTrue($walk->isDraft());
        $this->assertSame('Line 3 morning walk', $walk->title);
        $this->assertSame('Assembly Line 3', $walk->area);
    }

    // -- Edit ---------------------------------------------------------------

    public function test_owner_can_edit_their_draft_walk(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create(['title' => 'Old title']);

        $this->actingAs($owner);

        Volt::test('walks.edit', ['walk' => $walk])
            ->assertSet('title', 'Old title')
            ->set('title', 'Updated title')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirectToRoute('walks.show', ['walk' => $walk->id]);

        $this->assertSame('Updated title', $walk->refresh()->title);
    }

    public function test_other_user_gets_403_on_edit(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->actingAs($other);

        Volt::test('walks.edit', ['walk' => $walk])
            ->assertForbidden();
    }

    public function test_other_user_gets_403_on_show(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->actingAs($other);

        Volt::test('walks.show', ['walk' => $walk])
            ->assertForbidden();
    }

    public function test_admin_can_view_show_but_gets_403_on_edit(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->actingAs($admin);

        Volt::test('walks.show', ['walk' => $walk])
            ->assertSee($walk->title);

        Volt::test('walks.edit', ['walk' => $walk])
            ->assertForbidden();
    }

    // -- Delete -------------------------------------------------------------

    public function test_owner_can_delete_walk(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->actingAs($owner);

        Volt::test('walks.edit', ['walk' => $walk])
            ->call('delete')
            ->assertHasNoErrors()
            ->assertRedirectToRoute('walks.index');

        $this->assertDatabaseMissing('gemba_walks', ['id' => $walk->id]);
    }

    public function test_non_owner_without_delete_any_gets_403_on_delete(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->actingAs($other);

        // The delete action lives on the edit page, which is forbidden to non-owners.
        Volt::test('walks.edit', ['walk' => $walk])
            ->assertForbidden();

        $this->assertDatabaseHas('gemba_walks', ['id' => $walk->id]);
    }
}
