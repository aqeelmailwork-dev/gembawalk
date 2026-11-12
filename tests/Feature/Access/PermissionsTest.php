<?php

namespace Tests\Feature\Access;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_passes_gate_for_every_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::SuperAdmin->value);

        $this->actingAs($superAdmin);

        foreach (Permission::cases() as $permission) {
            $this->assertTrue(
                Gate::allows($permission->value),
                "super_admin should be allowed to {$permission->value}"
            );
        }
    }

    public function test_admin_has_exactly_the_default_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        $expected = [
            Permission::UsersApprove->value,
            Permission::UsersManage->value,
            Permission::WalksViewAll->value,
            Permission::ReportsExport->value,
            Permission::ChecklistsManage->value,
        ];

        $actual = $admin->getAllPermissions()->pluck('name')->sort()->values()->toArray();

        sort($expected);

        $this->assertSame($expected, $actual);
    }

    public function test_plain_user_cannot_users_approve(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(Role::User->value);

        $this->assertFalse($user->can(Permission::UsersApprove->value));
        $this->assertTrue($user->can(Permission::ReportsExport->value));
    }

    public function test_seeders_are_idempotent(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertDatabaseCount('permissions', count(Permission::cases()));
        $this->assertDatabaseCount('roles', count(Role::cases()));

        $this->seed(SuperAdminSeeder::class);
        $this->seed(SuperAdminSeeder::class);

        $this->assertSame(1, User::where('email', 'superadmin@gembawalk.test')->count());
    }
}
