<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Notifications\AccountApproved;
use App\Notifications\AccountRejected;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        Notification::fake();
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

    public function test_plain_user_gets_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::User->value);

        $this->actingAs($user)->get('/admin/users')->assertForbidden();
    }

    public function test_admin_and_super_admin_can_view_page(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('Users');

        $this->actingAs($this->superAdmin())
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('Users');
    }

    public function test_admin_can_approve_pending_user(): void
    {
        $admin = $this->admin();
        $user = User::factory()->pending()->create();

        $this->actingAs($admin);

        Volt::test('admin.users')
            ->call('approve', $user->id)
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertTrue($user->isApproved());
        $this->assertSame($admin->id, $user->approved_by);
        $this->assertNotNull($user->approved_at);
        $this->assertNull($user->rejection_reason);

        Notification::assertSentTo($user, AccountApproved::class);
    }

    public function test_bulk_approve_works(): void
    {
        $admin = $this->admin();
        $userA = User::factory()->pending()->create();
        $userB = User::factory()->pending()->create();

        $this->actingAs($admin);

        Volt::test('admin.users')
            ->set('selected', [$userA->id, $userB->id])
            ->call('approveSelected')
            ->assertHasNoErrors();

        $this->assertTrue($userA->refresh()->isApproved());
        $this->assertTrue($userB->refresh()->isApproved());

        Notification::assertSentTo($userA, AccountApproved::class);
        Notification::assertSentTo($userB, AccountApproved::class);
    }

    public function test_reject_stores_reason_and_sends_notification(): void
    {
        $admin = $this->admin();
        $user = User::factory()->pending()->create();

        $this->actingAs($admin);

        Volt::test('admin.users')
            ->call('reject', $user->id, 'Insufficient information provided.')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertTrue($user->isRejected());
        $this->assertSame('Insufficient information provided.', $user->rejection_reason);

        Notification::assertSentTo($user, AccountRejected::class, function (AccountRejected $notification) {
            return $notification->reason === 'Insufficient information provided.';
        });
    }

    public function test_admin_cannot_change_role_by_default(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $user->assignRole(Role::User->value);

        $this->actingAs($admin);

        Volt::test('admin.users')
            ->call('changeRole', $user->id, Role::Admin->value)
            ->assertForbidden();

        $this->assertFalse($user->refresh()->hasRole(Role::Admin->value));
    }

    public function test_super_admin_can_make_a_user_an_admin(): void
    {
        $superAdmin = $this->superAdmin();
        $user = User::factory()->create();
        $user->assignRole(Role::User->value);

        $this->actingAs($superAdmin);

        Volt::test('admin.users')
            ->call('changeRole', $user->id, Role::Admin->value)
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertTrue($user->hasRole(Role::Admin->value));
        $this->assertFalse($user->hasRole(Role::User->value));
    }

    public function test_admin_cannot_act_on_a_super_admin(): void
    {
        $admin = $this->admin();
        $superAdmin = $this->superAdmin();

        $this->actingAs($admin);

        Volt::test('admin.users')
            ->call('deactivate', $superAdmin->id)
            ->assertForbidden();

        Volt::test('admin.users')
            ->call('approve', $superAdmin->id)
            ->assertForbidden();

        $this->assertTrue($superAdmin->refresh()->isApproved());
    }

    public function test_user_cannot_act_on_self(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin);

        Volt::test('admin.users')
            ->call('deactivate', $admin->id)
            ->assertForbidden();

        Volt::test('admin.users')
            ->call('approve', $admin->id)
            ->assertForbidden();

        $this->assertTrue($admin->refresh()->isApproved());
    }

    public function test_cannot_demote_the_last_super_admin(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin);

        Volt::test('admin.users')
            ->call('changeRole', $superAdmin->id, Role::Admin->value)
            ->assertForbidden();

        $this->assertTrue($superAdmin->refresh()->hasRole(Role::SuperAdmin->value));
    }

    public function test_super_admin_can_demote_another_super_admin_when_two_exist(): void
    {
        $superAdmin = $this->superAdmin();
        $otherSuperAdmin = $this->superAdmin();

        $this->actingAs($superAdmin);

        Volt::test('admin.users')
            ->call('changeRole', $otherSuperAdmin->id, Role::Admin->value)
            ->assertHasNoErrors();

        $otherSuperAdmin->refresh();

        $this->assertFalse($otherSuperAdmin->hasRole(Role::SuperAdmin->value));
        $this->assertTrue($otherSuperAdmin->hasRole(Role::Admin->value));
    }

    public function test_search_filters_by_email(): void
    {
        $admin = $this->admin();

        User::factory()->create(['name' => 'Alice', 'email' => 'needle@example.com']);
        User::factory()->create(['name' => 'Bob', 'email' => 'other@example.com']);

        $this->actingAs($admin);

        Volt::test('admin.users')
            ->set('status', 'all')
            ->set('search', 'needle@example.com')
            ->assertSee('needle@example.com')
            ->assertDontSee('other@example.com');
    }

    public function test_status_filter_shows_correct_rows(): void
    {
        $admin = $this->admin();

        User::factory()->pending()->create(['name' => 'Pending Person', 'email' => 'pending@example.com']);
        User::factory()->create(['name' => 'Approved Person', 'email' => 'approved@example.com']);

        $this->actingAs($admin);

        Volt::test('admin.users')
            ->set('status', 'pending')
            ->assertSee('pending@example.com')
            ->assertDontSee('approved@example.com');
    }

    public function test_admin_with_roles_manage_can_change_role_but_not_to_super_admin(): void
    {
        $admin = $this->admin();
        $admin->givePermissionTo(Permission::RolesManage->value);

        $user = User::factory()->create();
        $user->assignRole(Role::User->value);

        $this->actingAs($admin);

        Volt::test('admin.users')
            ->call('changeRole', $user->id, Role::Admin->value)
            ->assertHasNoErrors();

        $this->assertTrue($user->refresh()->hasRole(Role::Admin->value));

        Volt::test('admin.users')
            ->call('changeRole', $user->id, Role::SuperAdmin->value)
            ->assertForbidden();

        $this->assertFalse($user->refresh()->hasRole(Role::SuperAdmin->value));
    }
}
