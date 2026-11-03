<?php

namespace Tests\Feature\Walks;

use App\Enums\ActionStatus;
use App\Enums\FindingCategory;
use App\Enums\Severity;
use App\Models\Finding;
use App\Models\GembaWalk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GembaWalkModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_findings_by_category_includes_zero_for_every_case(): void
    {
        $walk = GembaWalk::factory()->create();

        Finding::factory()->for($walk, 'walk')->create(['category' => FindingCategory::Safety->value]);
        Finding::factory()->for($walk, 'walk')->create(['category' => FindingCategory::Safety->value]);
        Finding::factory()->for($walk, 'walk')->create(['category' => FindingCategory::Waste->value]);

        $this->assertSame([
            'safety' => 2,
            'quality' => 0,
            'five_s' => 0,
            'waste' => 1,
            'process' => 0,
            'other' => 0,
        ], $walk->findingsByCategory());
    }

    public function test_findings_by_severity_includes_zero_for_every_case(): void
    {
        $walk = GembaWalk::factory()->create();

        Finding::factory()->for($walk, 'walk')->create(['severity' => Severity::High->value]);
        Finding::factory()->for($walk, 'walk')->create(['severity' => Severity::High->value]);
        Finding::factory()->for($walk, 'walk')->create(['severity' => Severity::Low->value]);

        $this->assertSame([
            'low' => 1,
            'medium' => 0,
            'high' => 2,
        ], $walk->findingsBySeverity());
    }

    public function test_open_actions_count_only_counts_open_actions(): void
    {
        $walk = GembaWalk::factory()->create();

        Finding::factory()->for($walk, 'walk')->create(['action_status' => ActionStatus::Open->value]);
        Finding::factory()->for($walk, 'walk')->create(['action_status' => ActionStatus::Open->value]);
        Finding::factory()->for($walk, 'walk')->create(['action_status' => ActionStatus::Done->value]);

        $this->assertSame(2, $walk->openActionsCount());
    }

    public function test_is_overdue_only_for_open_findings_with_a_past_due_date(): void
    {
        $walk = GembaWalk::factory()->create();

        $overdue = Finding::factory()->for($walk, 'walk')->overdue()->create();
        $done = Finding::factory()->for($walk, 'walk')->done()->create([
            'due_on' => now()->subDay()->toDateString(),
        ]);
        $future = Finding::factory()->for($walk, 'walk')->create([
            'due_on' => now()->addDay()->toDateString(),
        ]);

        $this->assertTrue($overdue->isOverdue());
        $this->assertFalse($done->isOverdue());
        $this->assertFalse($future->isOverdue());
    }
}
