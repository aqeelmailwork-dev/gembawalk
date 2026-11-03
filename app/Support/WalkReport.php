<?php

namespace App\Support;

use App\Enums\ActionStatus;
use App\Enums\FindingCategory;
use App\Enums\Severity;
use App\Models\Finding;
use App\Models\GembaWalk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Aggregates a gemba walk and its findings into the numbers and collections
 * shown on both the on-screen report and the downloaded PDF. This keeps the
 * calculations in one place so the two renderings can never drift apart.
 */
class WalkReport
{
    public readonly GembaWalk $walk;

    public readonly Collection $findings;

    public function __construct(GembaWalk $walk)
    {
        $walk->loadMissing(['findings', 'user']);

        $this->walk = $walk;

        $this->findings = $walk->findings
            ->sortBy([['position', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * Key counts used by the KPI row.
     *
     * @return array{findings: int, high: int, medium: int, low: int, open_actions: int, done_actions: int, overdue: int}
     */
    public function totals(): array
    {
        $high = $medium = $low = $open = $done = $overdue = 0;

        foreach ($this->findings as $finding) {
            if ($finding->severity === Severity::High) {
                $high++;
            } elseif ($finding->severity === Severity::Medium) {
                $medium++;
            } else {
                $low++;
            }

            if ($finding->action_status === ActionStatus::Open) {
                $open++;

                if ($finding->isOverdue()) {
                    $overdue++;
                }
            } else {
                $done++;
            }
        }

        return [
            'findings' => $this->findings->count(),
            'high' => $high,
            'medium' => $medium,
            'low' => $low,
            'open_actions' => $open,
            'done_actions' => $done,
            'overdue' => $overdue,
        ];
    }

    /**
     * Percentage of actionable findings that are done, rounded to an integer.
     */
    public function actionCompletionPercent(): int
    {
        $actionable = $this->findings->filter(fn (Finding $finding) => filled($finding->action_required));

        if ($actionable->isEmpty()) {
            return 0;
        }

        $done = $actionable->filter(fn (Finding $finding) => $finding->action_status === ActionStatus::Done)->count();

        return (int) round($done / $actionable->count() * 100);
    }

    /**
     * Count and percentage for every category, sorted by count descending.
     *
     * @return array<int, array{category: FindingCategory, count: int, percent: float}>
     */
    public function byCategory(): array
    {
        return collect(FindingCategory::cases())
            ->map(fn (FindingCategory $category) => [
                'category' => $category,
                'count' => $this->findings->filter(fn (Finding $finding) => $finding->category === $category)->count(),
            ])
            ->map(fn (array $entry) => $entry + ['percent' => $this->percent($entry['count'])])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Count and percentage for every severity, ordered high, medium, low.
     *
     * @return array<int, array{severity: Severity, count: int, percent: float}>
     */
    public function bySeverity(): array
    {
        return collect([Severity::High, Severity::Medium, Severity::Low])
            ->map(fn (Severity $severity) => [
                'severity' => $severity,
                'count' => $this->findings->filter(fn (Finding $finding) => $finding->severity === $severity)->count(),
            ])
            ->map(fn (array $entry) => $entry + ['percent' => $this->percent($entry['count'])])
            ->all();
    }

    /**
     * Open findings to surface first, capped at five.
     */
    public function priorities(): Collection
    {
        return $this->findings
            ->filter(fn (Finding $finding) => $finding->action_status === ActionStatus::Open)
            ->sort(function (Finding $a, Finding $b) {
                $byWeight = $b->severity->weight() <=> $a->severity->weight();

                if ($byWeight !== 0) {
                    return $byWeight;
                }

                $byDue = $this->dueTimestamp($a) <=> $this->dueTimestamp($b);

                return $byDue !== 0 ? $byDue : ($a->position <=> $b->position);
            })
            ->values()
            ->take(5);
    }

    /**
     * Findings with an action required, ordered by due date (nulls last).
     */
    public function actionPlan(): Collection
    {
        return $this->findings
            ->filter(fn (Finding $finding) => filled($finding->action_required))
            ->sort(function (Finding $a, Finding $b) {
                $byDue = $this->dueTimestamp($a) <=> $this->dueTimestamp($b);

                return $byDue !== 0 ? $byDue : ($a->position <=> $b->position);
            })
            ->values();
    }

    /**
     * Base64 data URI for a finding thumbnail, or null when it cannot be
     * embedded (missing file, unreadable bytes, or not a genuine image).
     */
    public function thumbnailDataUri(Finding $finding): ?string
    {
        if (! $finding->thumbnail_path) {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($finding->thumbnail_path)) {
            return null;
        }

        $contents = $disk->get($finding->thumbnail_path);

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        $info = @getimagesizefromstring($contents);

        if ($info === false || ! isset($info['mime'])) {
            return null;
        }

        return 'data:' . $info['mime'] . ';base64,' . base64_encode($contents);
    }

    /**
     * A finding's share of the total, as a percentage rounded to one decimal.
     */
    private function percent(int $count): float
    {
        $total = $this->findings->count();

        return $total > 0 ? round($count / $total * 100, 1) : 0.0;
    }

    /**
     * Timestamp used to order null due dates last.
     */
    private function dueTimestamp(Finding $finding): int
    {
        return $finding->due_on?->getTimestamp() ?? PHP_INT_MAX;
    }
}
