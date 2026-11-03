<?php

namespace Tests\Feature\Walks;

use App\Models\Finding;
use App\Models\GembaWalk;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FindingPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_owner_can_create_a_finding_on_their_draft_walk(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->assertTrue($owner->can('create', [Finding::class, $walk]));
    }

    public function test_non_owner_cannot_create_a_finding(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->assertFalse($other->can('create', [Finding::class, $walk]));
    }

    public function test_cannot_create_a_finding_on_a_completed_walk(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->completed()->for($owner)->create();

        $this->assertFalse($owner->can('create', [Finding::class, $walk]));
    }

    public function test_owner_can_update_a_finding_on_their_draft_walk(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();
        $finding = Finding::factory()->for($walk, 'walk')->create();

        $this->assertTrue($owner->can('update', $finding));
    }

    public function test_owner_cannot_update_a_finding_on_a_completed_walk(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->completed()->for($owner)->create();
        $finding = Finding::factory()->for($walk, 'walk')->create();

        $this->assertFalse($owner->can('update', $finding));
    }

    public function test_owner_can_delete_a_finding_on_their_draft_walk(): void
    {
        $owner = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();
        $finding = Finding::factory()->for($walk, 'walk')->create();

        $this->assertTrue($owner->can('delete', $finding));
    }

    public function test_other_user_cannot_delete_a_finding(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $walk = GembaWalk::factory()->for($owner)->create();
        $finding = Finding::factory()->for($walk, 'walk')->create();

        $this->assertFalse($other->can('delete', $finding));
    }
}
