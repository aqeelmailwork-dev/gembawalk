<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Support\PermissionMatrix;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

class AccessControlTest extends TestCase
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

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::Admin->value);

        return $user;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/access')->assertRedirect('/login');
    }

    public function test_plain_user_gets_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::User->value);

        $this->actingAs($user)->get('/admin/access')->assertForbidden();
    }

    public function test_admin_gets_forbidden(): void
    {
        $this->actingAs($this->admin())->get('/admin/access')->assertForbidden();
    }

    public function test_super_admin_sees_page_with_all_permission_labels(): void
    {
        $response = $this->actingAs($this->superAdmin())->get('/admin/access');

        $response->assertOk();

        foreach (Permission::cases() as $permission) {
            $response->assertSee($permission->label());
        }
    }

    public function test_super_admin_can_grant_a_permission_to_admin(): void
    {
        $this->actingAs($this->superAdmin());

        Volt::test('admin.access')
            ->set('matrix.admin.WalksDeleteAny', true)
            ->call('save')
            ->assertHasNoErrors();

        $adminRole = RoleModel::findByName(Role::Admin->value);

        $this->assertTrue($adminRole->hasPermissionTo(Permission::WalksDeleteAny->value));

        $admin = $this->admin();

        $this->assertTrue(Gate::forUser($admin)->allows(Permission::WalksDeleteAny->value));
    }

    public function test_save_ignores_fake_permission_and_non_configurable_role(): void
    {
        $this->actingAs($this->superAdmin());

        Volt::test('admin.access')
            ->set('matrix.admin.FakePermission', true)
            ->set('matrix.super_admin.UsersApprove', true)
            ->call('save')
            ->assertHasNoErrors();

        $adminRole = RoleModel::findByName(Role::Admin->value);

        $this->assertNotContains('FakePermission', $adminRole->permissions->pluck('name')->all());
        $this->assertDatabaseMissing('permissions', ['name' => 'FakePermission']);
    }

    public function test_reset_restores_defaults(): void
    {
        $adminRole = RoleModel::findByName(Role::Admin->value);

        $adminRole->givePermissionTo(Permission::WalksDeleteAny->value);
        $adminRole->revokePermissionTo(Permission::UsersApprove->value);

        PermissionMatrix::reset();

        $adminRole->refresh();

        $this->assertFalse($adminRole->hasPermissionTo(Permission::WalksDeleteAny->value));
        $this->assertTrue($adminRole->hasPermissionTo(Permission::UsersApprove->value));
        $this->assertTrue($adminRole->hasPermissionTo(Permission::ReportsExport->value));
    }

    public function test_reset_to_defaults_via_component(): void
    {
        $adminRole = RoleModel::findByName(Role::Admin->value);
        $adminRole->givePermissionTo(Permission::WalksDeleteAny->value);

        $this->actingAs($this->superAdmin());

        Volt::test('admin.access')
            ->call('resetToDefaults')
            ->assertHasNoErrors();

        $adminRole->refresh();

        $this->assertFalse($adminRole->hasPermissionTo(Permission::WalksDeleteAny->value));
    }

    public function test_super_admin_seeder_does_not_reset_changed_password(): void
    {
        $this->seed(SuperAdminSeeder::class);

        $user = User::where('email', 'superadmin@gembawalk.test')->firstOrFail();
        $user->password = Hash::make('new-secret-password');
        $user->save();

        $this->seed(SuperAdminSeeder::class);

        $user->refresh();

        $this->assertTrue(Hash::check('new-secret-password', $user->password));
    }
}
