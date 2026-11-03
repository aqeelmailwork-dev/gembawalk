<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_landing_page_with_login_and_register_links(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('See the floor.')
            ->assertSee(route('login'))
            ->assertSee(route('register'));
    }

    public function test_authenticated_user_sees_dashboard_link(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee(route('dashboard'));
    }
}
