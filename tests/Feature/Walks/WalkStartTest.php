<?php

namespace Tests\Feature\Walks;

use App\Enums\Role;
use App\Models\ChecklistAnswer;
use App\Models\ChecklistCategory;
use App\Models\ChecklistItem;
use App\Models\GembaWalk;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WalkStartTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ChecklistCategory $office;

    private ChecklistCategory $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole(Role::User->value);

        $this->office = ChecklistCategory::factory()->create(['name' => 'Office', 'position' => 1]);
        ChecklistItem::factory()->for($this->office, 'category')->create(['question' => 'Desk area clean?']);

        $this->warehouse = ChecklistCategory::factory()->create(['name' => 'Warehouse', 'position' => 2]);
        ChecklistItem::factory()->for($this->warehouse, 'category')->create(['question' => 'Exits clear?']);
    }

    public function test_new_walk_page_lists_categories_instead_of_a_form(): void
    {
        $empty = ChecklistCategory::factory()->create(['name' => 'Empty area']);
        $hidden = ChecklistCategory::factory()->inactive()->create(['name' => 'Hidden area']);
        ChecklistItem::factory()->for($hidden, 'category')->create();

        $this->actingAs($this->user)
            ->get(route('walks.create'))
            ->assertOk()
            ->assertSee('What are you checking today?')
            ->assertSeeInOrder(['Office', 'Warehouse'])
            ->assertDontSee('Empty area')
            ->assertDontSee('Hidden area')
            ->assertDontSee('placeholder="Line 3 morning walk"', false);
    }

    public function test_tapping_a_category_starts_a_dated_draft_walk_for_it(): void
    {
        $response = Volt::actingAs($this->user)->test('walks.create')->call('start', $this->office->id);

        $walk = GembaWalk::latest('id')->firstOrFail();

        $response->assertRedirectToRoute('walks.show', ['walk' => $walk->id]);

        $this->assertSame('Office walk', $walk->title);
        $this->assertSame('Office', $walk->area);
        $this->assertSame(now()->toDateString(), $walk->walked_on->toDateString());
        $this->assertSame($this->office->id, $walk->checklist_category_id);
        $this->assertSame($this->user->id, $walk->user_id);
        $this->assertTrue($walk->isDraft());
    }

    public function test_hidden_or_unknown_categories_cannot_be_started(): void
    {
        $this->office->update(['is_active' => false]);

        Volt::actingAs($this->user)->test('walks.create')->call('start', $this->office->id)->assertNotFound();
        Volt::actingAs($this->user)->test('walks.create')->call('start', 999999)->assertNotFound();

        $this->assertSame(0, GembaWalk::count());
    }

    public function test_typed_form_is_refused_while_categories_exist(): void
    {
        Volt::actingAs($this->user)->test('walks.create')
            ->set('title', 'Sneaky')
            ->set('area', 'Somewhere')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, GembaWalk::count());
    }

    public function test_walk_shows_only_its_category_already_open(): void
    {
        Volt::actingAs($this->user)->test('walks.create')->call('start', $this->office->id);
        $walk = GembaWalk::latest('id')->firstOrFail();

        Volt::actingAs($this->user)->test('walks.checklist', ['walk' => $walk])
            ->assertSet('openCategoryId', $this->office->id)
            ->assertSee('Desk area clean?')
            ->assertDontSee('Warehouse')
            ->assertDontSee('Exits clear?');
    }

    public function test_checks_from_another_category_cannot_be_answered(): void
    {
        Volt::actingAs($this->user)->test('walks.create')->call('start', $this->office->id);
        $walk = GembaWalk::latest('id')->firstOrFail();

        $exits = $this->warehouse->items()->firstOrFail();

        Volt::actingAs($this->user)->test('walks.checklist', ['walk' => $walk])
            ->call('answer', $exits->id, 'yes')
            ->assertNotFound();

        $this->assertSame(0, ChecklistAnswer::count());
    }

    public function test_older_walks_without_a_category_still_offer_every_category(): void
    {
        $walk = GembaWalk::factory()->for($this->user)->create();

        Volt::actingAs($this->user)->test('walks.checklist', ['walk' => $walk])
            ->assertSet('openCategoryId', null)
            ->assertSee('Office')
            ->assertSee('Warehouse');
    }

    public function test_deleting_the_category_keeps_the_walk(): void
    {
        Volt::actingAs($this->user)->test('walks.create')->call('start', $this->office->id);
        $walk = GembaWalk::latest('id')->firstOrFail();

        $this->office->delete();

        $walk->refresh();
        $this->assertNull($walk->checklist_category_id);
        $this->assertSame('Office walk', $walk->title);
    }
}
