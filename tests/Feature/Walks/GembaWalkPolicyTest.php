<?php

namespace Tests\Feature\Walks;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Finding;
use App\Models\GembaWalk;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GembaWalkPolicyTest extends TestCase
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

    public function test_owner_can_update_a_draft_walk(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->assertTrue($owner->can('update', $walk));
    }

    public function test_owner_cannot_update_a_completed_walk(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->completed()->for($owner)->create();

        $this->assertFalse($owner->can('update', $walk));
    }

    public function test_other_user_cannot_view_someone_elses_walk(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->assertFalse($other->can('view', $walk));
    }

    public function test_admin_can_view_but_not_update_someone_elses_walk(): void
    {
        $admin = $this->userWithRole(Role::Admin);
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->assertTrue($admin->can('view', $walk));
        $this->assertFalse($admin->can('update', $walk));
    }

    public function test_admin_cannot_delete_others_walk_unless_delete_any_granted(): void
    {
        $admin = $this->userWithRole(Role::Admin);
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->assertFalse($admin->can('delete', $walk));

        $admin->givePermissionTo(Permission::WalksDeleteAny->value);

        $this->assertTrue($admin->can('delete', $walk));
    }

    public function test_super_admin_can_delete_any_walk(): void
    {
        $superAdmin = $this->userWithRole(Role::SuperAdmin);
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->assertTrue($superAdmin->can('delete', $walk));
    }

    public function test_complete_requires_at_least_one_finding(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->assertFalse($owner->can('complete', $walk));

        Finding::factory()->for($walk, 'walk')->create();

        $this->assertTrue($owner->can('complete', $walk));
    }

    public function test_a_checklist_answer_is_enough_to_complete(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $answer = new \App\Models\ChecklistAnswer(['answer' => 'yes']);
        $answer->gemba_walk_id = $walk->id;
        $answer->category_name = 'Office';
        $answer->question = 'Desk area clean?';
        $answer->save();

        $this->assertTrue($owner->can('complete', $walk));
    }

    public function test_export_report_requires_reports_export_permission(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::User->value);

        $walk = GembaWalk::factory()->for($owner)->create();

        // Plain users receive reports.export by default.
        $this->assertTrue($owner->can('exportReport', $walk));

        RoleModel::findByName(Role::User->value)
            ->revokePermissionTo(Permission::ReportsExport->value);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($owner->can('exportReport', $walk));
    }

    public function test_pending_user_cannot_create_a_walk(): void
    {
        $pending = User::factory()->pending()->create();

        $this->assertFalse($pending->can('create', GembaWalk::class));
    }
}
