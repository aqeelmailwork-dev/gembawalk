<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\ChecklistAnswer;
use App\Models\ChecklistCategory;
use App\Models\ChecklistItem;
use App\Models\GembaWalk;
use App\Models\User;
use Database\Seeders\ChecklistSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ChecklistAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    public function test_page_access_follows_the_permission(): void
    {
        $this->actingAs($this->userWithRole(Role::User))->get('/admin/checklists')->assertForbidden();
        $this->actingAs($this->userWithRole(Role::Admin))->get('/admin/checklists')->assertOk()->assertSee('Checklists');
        $this->actingAs($this->userWithRole(Role::SuperAdmin))->get('/admin/checklists')->assertOk();
    }

    public function test_admins_get_the_permission_by_default_and_super_admin_can_revoke_it(): void
    {
        $admin = $this->userWithRole(Role::Admin);
        $this->assertTrue($admin->can(Permission::ChecklistsManage->value));

        RoleModel::findByName(Role::Admin->value)->revokePermissionTo(Permission::ChecklistsManage->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin->fresh())->get('/admin/checklists')->assertForbidden();
    }

    public function test_actions_are_refused_without_the_permission(): void
    {
        $category = ChecklistCategory::factory()->create();

        Volt::actingAs($this->userWithRole(Role::User))
            ->test('admin.checklists')
            ->set('categoryName', 'Hacked')
            ->call('saveCategory')
            ->assertForbidden();

        Volt::actingAs($this->userWithRole(Role::User))
            ->test('admin.checklists')
            ->call('toggleCategory', $category->id)
            ->assertForbidden();

        $this->assertTrue($category->fresh()->is_active);
    }

    public function test_admin_creates_a_category_and_adds_checks(): void
    {
        Volt::actingAs($this->userWithRole(Role::Admin))
            ->test('admin.checklists')
            ->call('saveCategory')
            ->assertHasErrors(['categoryName' => 'required'])
            ->set('categoryName', 'Office')
            ->set('categoryDescription', 'Shared office floor')
            ->call('saveCategory')
            ->assertHasNoErrors();

        $office = ChecklistCategory::where('name', 'Office')->firstOrFail();
        $this->assertSame('Shared office floor', $office->description);

        Volt::actingAs($this->userWithRole(Role::Admin))
            ->test('admin.checklists')
            ->call('addItem', $office->id)
            ->assertHasErrors(["newQuestion.{$office->id}" => 'required'])
            ->set("newQuestion.{$office->id}", 'Desk area clean?')
            ->call('addItem', $office->id)
            ->set("newQuestion.{$office->id}", 'Bins emptied?')
            ->call('addItem', $office->id)
            ->assertSee('Desk area clean?');

        $this->assertSame(['Desk area clean?', 'Bins emptied?'], $office->items()->pluck('question')->all());
        $this->assertSame([1, 2], $office->items()->pluck('position')->all());
    }

    public function test_admin_edits_hides_and_reorders(): void
    {
        $first = ChecklistCategory::factory()->create(['name' => 'Office', 'position' => 1]);
        $second = ChecklistCategory::factory()->create(['name' => 'Warehouse', 'position' => 2]);
        $a = ChecklistItem::factory()->for($first, 'category')->create(['question' => 'A?', 'position' => 1]);
        $b = ChecklistItem::factory()->for($first, 'category')->create(['question' => 'B?', 'position' => 2]);

        Volt::actingAs($this->userWithRole(Role::Admin))
            ->test('admin.checklists')
            ->call('moveCategory', $second->id, -1)
            ->call('moveItem', $b->id, -1)
            ->call('toggleItem', $a->id)
            ->call('openEditItem', $a->id)
            ->set('itemQuestion', 'A renamed?')
            ->call('saveItem')
            ->call('openEditCategory', $first->id)
            ->set('categoryName', 'Front office')
            ->call('saveCategory');

        $this->assertSame(['Warehouse', 'Front office'], ChecklistCategory::ordered()->pluck('name')->all());
        $this->assertSame(['B?', 'A renamed?'], $first->items()->pluck('question')->all());
        $this->assertFalse($a->fresh()->is_active);
    }

    public function test_deleting_a_category_keeps_past_answers(): void
    {
        $category = ChecklistCategory::factory()->create(['name' => 'Office']);
        $item = ChecklistItem::factory()->for($category, 'category')->create(['question' => 'Desk clean?']);

        $answer = new ChecklistAnswer(['answer' => 'no']);
        $answer->gemba_walk_id = GembaWalk::factory()->create()->id;
        $answer->checklist_category_id = $category->id;
        $answer->checklist_item_id = $item->id;
        $answer->category_name = 'Office';
        $answer->question = 'Desk clean?';
        $answer->save();

        Volt::actingAs($this->userWithRole(Role::Admin))
            ->test('admin.checklists')
            ->call('confirmDeleteCategory', $category->id)
            ->call('deleteCategory');

        $this->assertDatabaseMissing('checklist_categories', ['id' => $category->id]);
        $this->assertDatabaseMissing('checklist_items', ['id' => $item->id]);

        $answer->refresh();
        $this->assertNull($answer->checklist_item_id);
        $this->assertSame('Office', $answer->category_name);
        $this->assertSame('Desk clean?', $answer->question);
    }

    public function test_example_seeder_only_runs_on_an_empty_checklist(): void
    {
        $this->seed(ChecklistSeeder::class);
        $this->assertTrue(ChecklistCategory::where('name', 'Office')->exists());
        $count = ChecklistItem::count();

        $this->seed(ChecklistSeeder::class);
        $this->assertSame($count, ChecklistItem::count());
    }
}
