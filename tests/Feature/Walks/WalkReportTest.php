<?php

namespace Tests\Feature\Walks;

use App\Enums\ActionStatus;
use App\Enums\FindingCategory;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Finding;
use App\Models\GembaWalk;
use App\Models\User;
use App\Support\WalkReport;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WalkReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::User->value);

        return $user;
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::Admin->value);

        return $user;
    }

    /**
     * A known six-finding dataset covering every category, both statuses and
     * one overdue action.
     */
    private function datasetWalk(): GembaWalk
    {
        $owner = $this->user();
        $walk = GembaWalk::factory()->for($owner)->create(['walked_on' => '2026-01-15']);

        $findings = [
            // position, category, severity, status, action, owner, due
            [1, FindingCategory::Safety, Severity::High, ActionStatus::Open, 'Install guard', 'Alice', null],
            [2, FindingCategory::Quality, Severity::Medium, ActionStatus::Open, 'Rework batch', 'Bob', now()->subDay()->toDateString()],
            [3, FindingCategory::Safety, Severity::Low, ActionStatus::Done, 'Clean spill', 'Carol', now()->addDays(2)->toDateString()],
            [4, FindingCategory::Waste, Severity::High, ActionStatus::Open, null, null, null],
            [5, FindingCategory::Process, Severity::Low, ActionStatus::Done, null, null, null],
            [6, FindingCategory::FiveS, Severity::Medium, ActionStatus::Open, 'Sort tools', null, now()->addDay()->toDateString()],
        ];

        foreach ($findings as [$position, $category, $severity, $status, $action, $owner, $due]) {
            Finding::factory()->for($walk, 'walk')->create([
                'position' => $position,
                'category' => $category->value,
                'severity' => $severity->value,
                'action_status' => $status->value,
                'action_required' => $action,
                'action_owner' => $owner,
                'due_on' => $due,
            ]);
        }

        return $walk;
    }

    // -- WalkReport aggregation ----------------------------------------------

    public function test_walk_report_aggregates_the_known_dataset(): void
    {
        $report = new WalkReport($this->datasetWalk());

        $this->assertSame([
            'findings' => 6,
            'high' => 2,
            'medium' => 2,
            'low' => 2,
            'open_actions' => 4,
            'done_actions' => 2,
            'overdue' => 1,
        ], $report->totals());

        $this->assertSame(25, $report->actionCompletionPercent());

        $byCategory = $report->byCategory();

        $this->assertCount(6, $byCategory);
        $this->assertSame(FindingCategory::Safety, $byCategory[0]['category']);
        $this->assertSame(2, $byCategory[0]['count']);
        $this->assertEquals(33.3, $byCategory[0]['percent']);
        $this->assertSame(FindingCategory::Other, $byCategory[5]['category']);
        $this->assertSame(0, $byCategory[5]['count']);
        $this->assertSame(0.0, $byCategory[5]['percent']);

        $bySeverity = $report->bySeverity();

        $this->assertSame(
            [Severity::High, Severity::Medium, Severity::Low],
            array_map(fn (array $entry) => $entry['severity'], $bySeverity),
        );
        $this->assertSame([2, 2, 2], array_map(fn (array $entry) => $entry['count'], $bySeverity));

        $this->assertSame([1, 4, 2, 6], $report->priorities()->pluck('position')->all());
        $this->assertSame([2, 6, 3, 1], $report->actionPlan()->pluck('position')->all());
    }

    // -- Report page ---------------------------------------------------------

    public function test_owner_sees_the_report_page(): void
    {
        $owner = $this->user();
        $walk = GembaWalk::factory()->for($owner)->create(['title' => 'Line 3 morning walk']);
        Finding::factory()->for($walk, 'walk')->create(['severity' => 'high']);

        $this->actingAs($owner)
            ->get(route('walks.report', $walk))
            ->assertOk()
            ->assertSee('Line 3 morning walk')
            ->assertSee('Findings')
            ->assertSee('High severity')
            ->assertSee('Open actions')
            ->assertSee('Overdue')
            ->assertSee('Actions completed');
    }

    public function test_admin_can_view_any_report_page(): void
    {
        $admin = $this->admin();
        $owner = $this->user();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->actingAs($admin)
            ->get(route('walks.report', $walk))
            ->assertOk();
    }

    public function test_other_user_cannot_view_the_report_page(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->actingAs($other)
            ->get(route('walks.report', $walk))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_from_the_report_page(): void
    {
        $walk = GembaWalk::factory()->create();

        $this->get(route('walks.report', $walk))
            ->assertRedirect(route('login'));
    }

    // -- PDF -----------------------------------------------------------------

    public function test_pdf_config_disables_inline_php(): void
    {
        $this->assertFalse(config('dompdf.options.enable_php'));
    }

    public function test_owner_can_download_the_pdf(): void
    {
        $owner = $this->user();
        $walk = GembaWalk::factory()->for($owner)->create([
            'title' => 'Line 3 morning walk',
            'walked_on' => '2026-01-15',
        ]);
        Finding::factory()->for($walk, 'walk')->create(['observation' => 'Spill on the floor']);

        $response = $this->actingAs($owner)->get(route('walks.report.pdf', $walk));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        $this->assertStringContainsString(
            'line-3-morning-walk',
            $response->headers->get('content-disposition'),
        );
    }

    public function test_owner_cannot_download_after_reports_export_is_revoked(): void
    {
        $owner = $this->user();
        $walk = GembaWalk::factory()->for($owner)->create();

        RoleModel::findByName(Role::User->value)
            ->revokePermissionTo(Permission::ReportsExport->value);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($owner)
            ->get(route('walks.report.pdf', $walk))
            ->assertForbidden();
    }

    public function test_admin_can_download_any_pdf(): void
    {
        $admin = $this->admin();
        $owner = $this->user();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->actingAs($admin)
            ->get(route('walks.report.pdf', $walk))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_other_user_cannot_download_the_pdf(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $walk = GembaWalk::factory()->for($owner)->create();

        $this->actingAs($other)
            ->get(route('walks.report.pdf', $walk))
            ->assertForbidden();
    }

    public function test_pdf_renders_when_a_finding_photo_file_is_missing(): void
    {
        $owner = $this->user();
        $walk = GembaWalk::factory()->for($owner)->create();
        $finding = Finding::factory()->for($walk, 'walk')->withPhoto()->create();

        Storage::disk('public')->delete($finding->thumbnail_path);

        $this->actingAs($owner)
            ->get(route('walks.report.pdf', $walk))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
