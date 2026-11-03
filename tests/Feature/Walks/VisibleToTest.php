<?php

namespace Tests\Feature\Walks;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\GembaWalk;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisibleToTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_user_sees_only_their_own_walks(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        GembaWalk::factory()->count(2)->for($owner)->create();
        GembaWalk::factory()->count(3)->for($other)->create();

        $visible = GembaWalk::visibleTo($owner)->get();

        $this->assertCount(2, $visible);
        $this->assertTrue($visible->every(fn (GembaWalk $walk) => $walk->user_id === $owner->id));
    }

    public function test_admin_with_view_all_sees_every_walk(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        GembaWalk::factory()->count(4)->create();

        $this->assertTrue($admin->can(Permission::WalksViewAll->value));
        $this->assertCount(4, GembaWalk::visibleTo($admin)->get());
    }
}
