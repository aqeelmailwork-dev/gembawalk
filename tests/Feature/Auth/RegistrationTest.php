<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\NewUserAwaitingApproval;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_register_as_pending_and_are_not_authenticated(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        Notification::fake();

        $response = Volt::test('auth.register')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register');

        $response
            ->assertHasNoErrors()
            ->assertRedirect(route('approval.pending', absolute: false));

        $this->assertGuest();

        $user = User::where('email', 'test@example.com')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->isPending());
        $this->assertSame(UserStatus::Pending, $user->status);
        $this->assertTrue($user->hasRole(Role::User->value));
    }

    public function test_registration_notifies_admins(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::SuperAdmin->value);

        $plainUser = User::factory()->create();
        $plainUser->assignRole(Role::User->value);

        Notification::fake();

        Volt::test('auth.register')
            ->set('name', 'New User')
            ->set('email', 'new@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register');

        Notification::assertSentTo($admin, NewUserAwaitingApproval::class);
        Notification::assertSentTo($superAdmin, NewUserAwaitingApproval::class);
        Notification::assertNotSentTo($plainUser, NewUserAwaitingApproval::class);
    }
}
