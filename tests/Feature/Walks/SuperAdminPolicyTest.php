<?php

namespace Tests\Feature\Walks;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\GembaWalk;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class SuperAdminPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::SuperAdmin->value);

        return $user;
    }

    public function test_super_admin_cannot_reopen_their_own_draft_walk(): void
    {
        $superAdmin = $this->superAdmin();
        $walk = GembaWalk::factory()->for($superAdmin)->create();

        $this->assertTrue($walk->isDraft());
        $this->assertFalse($superAdmin->can('reopen', $walk));
    }

    public function test_super_admin_cannot_complete_own_walk_with_zero_findings(): void
    {
        $superAdmin = $this->superAdmin();
        $walk = GembaWalk::factory()->for($superAdmin)->create();

        $this->assertFalse($superAdmin->can('complete', $walk));
    }

    public function test_super_admin_cannot_update_another_users_walk(): void
    {
        $superAdmin = $this->superAdmin();
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->assertFalse($superAdmin->can('update', $walk));
    }

    public function test_super_admin_can_view_another_users_walk(): void
    {
        $superAdmin = $this->superAdmin();
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->assertTrue($superAdmin->can('view', $walk));
    }

    public function test_super_admin_can_delete_another_users_walk(): void
    {
        $superAdmin = $this->superAdmin();
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->assertTrue($superAdmin->can('delete', $walk));
    }

    public function test_super_admin_passes_gate_for_every_permission_value(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin);

        foreach (Permission::cases() as $permission) {
            $this->assertTrue(
                Gate::allows($permission->value),
                "super_admin should be allowed to {$permission->value}"
            );
        }
    }

    public function test_super_admin_is_denied_an_unknown_ability(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin);

        $this->assertFalse(Gate::allows('some-random-ability'));
    }
}
