<?php

namespace Tests\Feature\Walks;

use App\Models\Finding;
use App\Models\GembaWalk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_deleting_a_finding_removes_its_files(): void
    {
        $walk = GembaWalk::factory()->create();
        $finding = Finding::factory()->for($walk, 'walk')->withPhoto()->create();

        Storage::disk('public')->assertExists($finding->photo_path);
        Storage::disk('public')->assertExists($finding->thumbnail_path);

        $finding->delete();

        Storage::disk('public')->assertMissing($finding->photo_path);
        Storage::disk('public')->assertMissing($finding->thumbnail_path);
    }

    public function test_deleting_a_walk_removes_its_findings_and_their_files(): void
    {
        $walk = GembaWalk::factory()->create();
        $findingA = Finding::factory()->for($walk, 'walk')->withPhoto()->create();
        $findingB = Finding::factory()->for($walk, 'walk')->withPhoto()->create();

        Storage::disk('public')->assertExists($findingA->photo_path);
        Storage::disk('public')->assertExists($findingB->photo_path);

        $walk->delete();

        $this->assertDatabaseMissing('findings', ['id' => $findingA->id]);
        $this->assertDatabaseMissing('findings', ['id' => $findingB->id]);

        Storage::disk('public')->assertMissing($findingA->photo_path);
        Storage::disk('public')->assertMissing($findingB->photo_path);
    }
}
