<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Enums\Role;
use App\Models\Finding;
use App\Models\GembaWalk;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_pending_users_are_redirected_to_the_login_page(): void
    {
        $user = User::factory()->pending()->create();

        $this->actingAs($user);

        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/dashboard');
        $response->assertStatus(200);
    }

    public function test_approved_user_with_no_walks_sees_the_welcome_message(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get('/dashboard')->assertSee('Welcome to GembaWalk');
    }

    public function test_user_sees_only_their_own_open_actions(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $ownerWalk = GembaWalk::factory()->for($owner)->create();
        Finding::factory()->for($ownerWalk, 'walk')->create([
            'observation' => 'Owner unique observation',
            'action_required' => 'Owner action',
            'action_status' => ActionStatus::Open->value,
        ]);

        $otherWalk = GembaWalk::factory()->for($other)->create();
        Finding::factory()->for($otherWalk, 'walk')->create([
            'observation' => 'Other unique observation',
            'action_required' => 'Other action',
            'action_status' => ActionStatus::Open->value,
        ]);

        $this->actingAs($owner);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Owner unique observation')
            ->assertDontSee('Other unique observation');
    }

    public function test_admin_sees_team_activity_and_the_pending_approval_banner(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        User::factory()->pending()->create();

        $other = User::factory()->create();
        GembaWalk::factory()->for($other)->create(['walked_on' => now()->toDateString()]);

        $this->actingAs($admin);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Showing your team')
            ->assertSee('waiting for approval')
            ->assertSee('Review')
            ->assertSee('Walks this month: 1', false);
    }

    public function test_plain_user_never_sees_the_pending_approval_banner(): void
    {
        User::factory()->pending()->create();

        $user = User::factory()->create();

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('waiting for approval');
    }

    public function test_overdue_count_is_correct(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        Finding::factory()->for($walk, 'walk')->create([
            'action_required' => 'Fix the leak',
            'action_status' => ActionStatus::Open->value,
            'due_on' => now()->subDay()->toDateString(),
        ]);

        Finding::factory()->for($walk, 'walk')->create([
            'action_required' => 'Check the pump',
            'action_status' => ActionStatus::Open->value,
            'due_on' => now()->addWeek()->toDateString(),
        ]);

        $this->actingAs($owner);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Open actions: 2', false)
            ->assertSee('Overdue: 1', false);
    }

    public function test_walks_this_month_count_is_correct(): void
    {
        $owner = User::factory()->create();

        GembaWalk::factory()->for($owner)->create(['walked_on' => now()->toDateString()]);
        GembaWalk::factory()->for($owner)->create(['walked_on' => now()->toDateString()]);
        GembaWalk::factory()->for($owner)->create(['walked_on' => now()->subMonthNoOverflow()->toDateString()]);

        $this->actingAs($owner);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Walks this month: 2', false);
    }
}
