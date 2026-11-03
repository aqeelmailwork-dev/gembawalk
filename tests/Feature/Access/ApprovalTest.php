<?php

namespace Tests\Feature\Access;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_user_cannot_log_in(): void
    {
        $user = User::factory()->pending()->create();

        Volt::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors(['email' => 'Your account is awaiting admin approval.']);

        $this->assertGuest();
    }

    public function test_rejected_user_cannot_log_in(): void
    {
        $user = User::factory()->rejected()->create([
            'rejection_reason' => 'Insufficient information provided.',
        ]);

        Volt::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors(['email' => 'Your account request was not approved. Insufficient information provided.']);

        $this->assertGuest();
    }

    public function test_rejected_user_without_reason_cannot_log_in(): void
    {
        $user = User::factory()->rejected()->create();

        Volt::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors(['email' => 'Your account request was not approved.']);

        $this->assertGuest();
    }

    public function test_approved_user_can_log_in(): void
    {
        $user = User::factory()->create();

        Volt::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_middleware_logs_out_a_user_whose_status_changes_to_pending(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $user->update(['status' => UserStatus::Pending->value]);

        $response = $this->get('/dashboard');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
