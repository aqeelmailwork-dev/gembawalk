<?php

namespace Tests\Feature\Walks;

use App\Models\Finding;
use App\Models\GembaWalk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FindingPhotoUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_photo_and_thumbnail_urls_are_root_relative(): void
    {
        $walk = GembaWalk::factory()->create();

        $finding = Finding::factory()->for($walk, 'walk')->create();
        $finding->photo_path = "findings/{$walk->id}/photo.jpg";
        $finding->thumbnail_path = "findings/{$walk->id}/thumbs/photo.jpg";
        $finding->save();

        $this->assertStringStartsWith('/storage/', $finding->photo_url);
        $this->assertStringStartsWith('/storage/', $finding->thumbnail_url);
    }
}
