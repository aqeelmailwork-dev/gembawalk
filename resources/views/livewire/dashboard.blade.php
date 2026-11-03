<?php

use App\Enums\ActionStatus;
use App\Enums\FindingCategory;
use App\Enums\Severity;
use App\Models\Finding;
use App\Models\GembaWalk;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component {
    private function viewer(): User
    {
        return auth()->user();
    }

    /**
     * Walks visible to the current user (own walks, or everyone's for admins).
     */
    private function visibleWalks(): Builder
    {
        return GembaWalk::query()->visibleTo($this->viewer());
    }

    /**
     * Findings whose walk is visible to the current user.
     */
    private function findingsBase(): Builder
    {
        return Finding::query()
            ->whereHas('walk', fn (Builder $query) => $query->visibleTo($this->viewer()));
    }

    public function canViewAll(): bool
    {
        return $this->viewer()->can('walks.view_all');
    }

    public function canCreate(): bool
    {
        return $this->viewer()->can('create', GembaWalk::class);
    }

    public function canApprove(): bool
    {
        return $this->viewer()->can('users.approve');
    }

    public function firstName(): string
    {
        $name = trim($this->viewer()->name);

        $first = Str::before($name, ' ');

        return $first !== '' ? $first : $name;
    }

    public function greeting(): string
    {
        $hour = (int) now()->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    public function todayLabel(): string
    {
        return now()->format('l, F j, Y');
    }

    public function scopeLabel(): string
    {
        return $this->canViewAll() ? "Showing your team's activity" : 'Showing your activity';
    }

    public function walksThisMonth(): int
    {
        return $this->visibleWalks()
            ->whereBetween('walked_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->count();
    }

    public function walksLastMonth(): int
    {
        $start = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $end = now()->subMonthNoOverflow()->endOfMonth()->toDateString();

        return $this->visibleWalks()
            ->whereBetween('walked_on', [$start, $end])
            ->count();
    }

    public function openActions(): int
    {
        return $this->findingsBase()
            ->where('action_status', ActionStatus::Open->value)
            ->count();
    }

    public function overdue(): int
    {
        return $this->findingsBase()
            ->where('action_status', ActionStatus::Open->value)
            ->whereNotNull('due_on')
            ->where('due_on', '<', now()->toDateString())
            ->count();
    }

    public function highSeverityOpen(): int
    {
        return $this->findingsBase()
            ->where('action_status', ActionStatus::Open->value)
            ->where('severity', Severity::High->value)
            ->count();
    }

    public function pendingUsers(): int
    {
        return User::pending()->count();
    }

    public function hasNoWalks(): bool
    {
        return $this->visibleWalks()->doesntExist();
    }

    /**
     * Up to six open findings that need action, overdue first then by due date.
     */
    public function openActionsList(): Collection
    {
        return $this->findingsBase()
            ->where('action_status', ActionStatus::Open->value)
            ->whereNotNull('action_required')
            ->with('walk')
            ->orderByRaw('(due_on IS NOT NULL AND due_on < ?) DESC', [now()->toDateString()])
            ->orderByRaw('due_on IS NULL ASC')
            ->orderBy('due_on')
            ->orderByDesc('id')
            ->limit(6)
            ->get();
    }

    /**
     * Findings per category for the last 30 days, with a share of the total.
     *
     * @return array<int, array{category: FindingCategory, count: int, percent: float}>
     */
    public function categoryBreakdown(): array
    {
        $counts = $this->findingsBase()
            ->where('created_at', '>=', now()->subDays(30)->startOfDay())
            ->selectRaw('category, COUNT(*) as aggregate')
            ->groupBy('category')
            ->pluck('aggregate', 'category');

        $total = $counts->sum();

        return collect(FindingCategory::cases())
            ->map(fn (FindingCategory $category) => [
                'category' => $category,
                'count' => (int) ($counts[$category->value] ?? 0),
                'percent' => $total > 0 ? round((int) ($counts[$category->value] ?? 0) / $total * 100, 1) : 0.0,
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Walk counts for the last eight ISO weeks, oldest first.
     *
     * @return array<int, array{label: string, count: int, percent: int}>
     */
    public function weeklyWalks(): array
    {
        $start = now()->startOfWeek()->subWeeks(7);

        $counts = $this->visibleWalks()
            ->where('walked_on', '>=', $start->toDateString())
            ->get(['walked_on'])
            ->groupBy(fn (GembaWalk $walk) => $walk->walked_on->startOfWeek()->toDateString())
            ->map->count();

        $weeks = [];
        $max = 0;

        for ($i = 0; $i < 8; $i++) {
            $weekStart = $start->copy()->addWeeks($i);
            $count = (int) ($counts[$weekStart->toDateString()] ?? 0);
            $max = max($max, $count);

            $weeks[] = [
                'label' => 'W'.$weekStart->format('W'),
                'count' => $count,
            ];
        }

        foreach ($weeks as &$week) {
            $week['percent'] = $max > 0 ? (int) round($week['count'] / $max * 100) : 0;
        }
        unset($week);

        return $weeks;
    }

    /**
     * The four most recent visible walks, with cover thumbnail and counts.
     */
    public function recentWalks(): Collection
    {
        return $this->visibleWalks()
            ->withCount('findings')
            ->with([
                'findings' => fn ($query) => $query
                    ->select(['id', 'gemba_walk_id', 'thumbnail_path', 'position'])
                    ->orderBy('position')
                    ->orderBy('id'),
            ])
            ->orderByDesc('walked_on')
            ->orderByDesc('id')
            ->limit(4)
            ->get();
    }
}; ?>

@php
    $greeting = $this->greeting();
    $firstName = $this->firstName();
    $todayLabel = $this->todayLabel();
    $scopeLabel = $this->scopeLabel();
    $canCreate = $this->canCreate();
    $canApprove = $this->canApprove();

    $walksThisMonth = $this->walksThisMonth();
    $walksLastMonth = $this->walksLastMonth();
    $openActions = $this->openActions();
    $overdue = $this->overdue();
    $highSeverityOpen = $this->highSeverityOpen();

    $pendingUsers = $canApprove ? $this->pendingUsers() : 0;

    $hasNoWalks = $this->hasNoWalks();

    $openActionsList = $hasNoWalks ? collect() : $this->openActionsList();
    $categoryBreakdown = $hasNoWalks ? [] : $this->categoryBreakdown();
    $weeklyWalks = $hasNoWalks ? [] : $this->weeklyWalks();
    $recentWalks = $hasNoWalks ? collect() : $this->recentWalks();

    $categoryBarColors = [
        'red' => 'bg-red-500',
        'blue' => 'bg-blue-500',
        'violet' => 'bg-violet-500',
        'amber' => 'bg-amber-500',
        'cyan' => 'bg-cyan-500',
        'zinc' => 'bg-zinc-500',
    ];

    $walksDelta = $walksThisMonth - $walksLastMonth;
    $walksDeltaLabel = match (true) {
        $walksDelta > 0 => '+'.$walksDelta.' vs last month',
        $walksDelta < 0 => $walksDelta.' vs last month',
        default => 'Same as last month',
    };
    $walksDeltaColor = match (true) {
        $walksDelta > 0 => 'text-green-600 dark:text-green-400',
        $walksDelta < 0 => 'text-amber-600 dark:text-amber-400',
        default => 'text-zinc-400 dark:text-zinc-500',
    };

    $pendingLabel = $pendingUsers === 1
        ? '1 person is waiting for approval'
        : $pendingUsers.' people are waiting for approval';
@endphp

<div class="flex flex-col gap-6">
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $greeting }}, {{ $firstName }}</flux:heading>
            <flux:subheading size="lg" class="text-zinc-500 dark:text-zinc-400">{{ $todayLabel }}</flux:subheading>
            <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">{{ $scopeLabel }}</p>
        </div>

        @if ($canCreate)
            <flux:button href="{{ route('walks.create') }}" icon="plus" variant="primary" wire:navigate>Start a walk</flux:button>
        @endif
    </div>

    {{-- KPI cards --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div
            class="rounded-xl border p-4 shadow-sm {{ $overdue > 0 ? 'border-red-200 dark:border-red-900' : 'border-zinc-200 dark:border-zinc-700' }} bg-white dark:bg-zinc-900"
            aria-label="Walks this month: {{ $walksThisMonth }}"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-2xl font-semibold text-zinc-800 dark:text-white">{{ $walksThisMonth }}</div>
                    <div class="mt-0.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">Walks this month</div>
                </div>
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-teal-500/10 dark:bg-teal-400/10">
                    <flux:icon name="map" class="size-5 text-teal-600 dark:text-teal-400" />
                </div>
            </div>
            <div class="mt-2 text-xs {{ $walksDeltaColor }}">{{ $walksDeltaLabel }}</div>
        </div>

        <div
            class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
            aria-label="Open actions: {{ $openActions }}"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-2xl font-semibold {{ $openActions > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-800 dark:text-white' }}">{{ $openActions }}</div>
                    <div class="mt-0.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">Open actions</div>
                </div>
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-amber-500/10 dark:bg-amber-400/10">
                    <flux:icon name="clipboard-document-list" class="size-5 text-amber-600 dark:text-amber-400" />
                </div>
            </div>
            <div class="mt-2 text-xs text-zinc-400 dark:text-zinc-500">{{ $highSeverityOpen }} high severity</div>
        </div>

        <div
            class="rounded-xl border p-4 shadow-sm {{ $overdue > 0 ? 'border-red-200 dark:border-red-900' : 'border-zinc-200 dark:border-zinc-700' }} bg-white dark:bg-zinc-900"
            aria-label="Overdue: {{ $overdue }}"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-2xl font-semibold {{ $overdue > 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-800 dark:text-white' }}">{{ $overdue }}</div>
                    <div class="mt-0.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">Overdue</div>
                </div>
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-red-500/10 dark:bg-red-400/10">
                    <flux:icon name="exclamation-triangle" class="size-5 text-red-600 dark:text-red-400" />
                </div>
            </div>
            <div class="mt-2 text-xs text-zinc-400 dark:text-zinc-500">of {{ $openActions }} open actions</div>
        </div>

        <div
            class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
            aria-label="High severity open: {{ $highSeverityOpen }}"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-2xl font-semibold {{ $highSeverityOpen > 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-800 dark:text-white' }}">{{ $highSeverityOpen }}</div>
                    <div class="mt-0.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">High severity open</div>
                </div>
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-red-500/10 dark:bg-red-400/10">
                    <flux:icon name="fire" class="size-5 text-red-600 dark:text-red-400" />
                </div>
            </div>
            <div class="mt-2 text-xs text-zinc-400 dark:text-zinc-500">{{ $overdue }} overdue</div>
        </div>
    </div>

    {{-- Pending approval banner --}}
    @if ($canApprove && $pendingUsers > 0)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-teal-200 bg-teal-50 p-4 dark:border-teal-900 dark:bg-teal-950/40">
            <div class="flex items-center gap-3">
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-teal-500/10 dark:bg-teal-400/10">
                    <flux:icon name="user-group" class="size-5 text-teal-600 dark:text-teal-400" />
                </div>
                <p class="text-sm font-medium text-teal-800 dark:text-teal-100">{{ $pendingLabel }}</p>
            </div>

            <flux:button :href="route('admin.users')" variant="primary" size="sm" wire:navigate>Review</flux:button>
        </div>
    @endif

    @if ($hasNoWalks)
        {{-- Onboarding --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-col items-center gap-6 py-6 text-center">
                <div class="flex size-16 items-center justify-center rounded-full bg-teal-500/10 dark:bg-teal-400/10">
                    <flux:icon name="camera" class="size-8 text-teal-600 dark:text-teal-400" />
                </div>

                <div>
                    <h2 class="text-xl font-semibold text-zinc-800 dark:text-white">Welcome to GembaWalk</h2>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Go see, take photos, capture what you find.</p>
                </div>

                <div class="flex flex-col gap-4 text-left sm:flex-row sm:gap-8">
                    <div class="flex items-start gap-3">
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-teal-600 text-xs font-semibold text-white">1</span>
                        <span class="text-sm text-zinc-600 dark:text-zinc-300">Start a walk</span>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-teal-600 text-xs font-semibold text-white">2</span>
                        <span class="text-sm text-zinc-600 dark:text-zinc-300">Snap photos of what you see</span>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-teal-600 text-xs font-semibold text-white">3</span>
                        <span class="text-sm text-zinc-600 dark:text-zinc-300">Describe findings &amp; share the report</span>
                    </div>
                </div>

                @if ($canCreate)
                    <flux:button href="{{ route('walks.create') }}" variant="primary" icon="plus" wire:navigate>Start your first walk</flux:button>
                @endif
            </div>
        </div>
    @else
        {{-- Open actions + charts --}}
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:col-span-2">
                <div class="flex items-center justify-between border-b border-zinc-200 p-5 dark:border-zinc-700">
                    <h2 class="text-base font-semibold text-zinc-800 dark:text-white">Open actions</h2>
                </div>

                @if ($openActionsList->isNotEmpty())
                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($openActionsList as $finding)
                            <a href="{{ route('walks.show', $finding->walk) }}" wire:navigate class="flex items-center gap-4 p-4 transition hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <div class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                    @if ($finding->thumbnail_url)
                                        <img src="{{ $finding->thumbnail_url }}" alt="Finding photo" class="size-full object-cover" loading="lazy" />
                                    @else
                                        <flux:icon name="photo" class="size-5 text-zinc-300 dark:text-zinc-600" />
                                    @endif
                                </div>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $finding->observation }}</p>
                                    <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $finding->action_required }}</p>
                                </div>

                                <div class="hidden shrink-0 text-right sm:block">
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $finding->action_owner ?? '—' }}</p>

                                    @if ($finding->isOverdue())
                                        <flux:badge color="red" size="sm">Overdue</flux:badge>
                                    @elseif ($finding->due_on)
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $finding->due_on->format('j M Y') }}</span>
                                    @else
                                        <span class="text-xs text-zinc-400 dark:text-zinc-500">—</span>
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center gap-3 py-12 text-center">
                        <flux:icon name="check-circle" class="size-8 text-teal-500" />
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">No open actions - nice work</p>
                    </div>
                @endif

                <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                    <a href="{{ route('walks.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-teal-600 hover:text-teal-500 dark:text-teal-400">
                        All walks
                        <flux:icon name="arrow-right" class="size-4" />
                    </a>
                </div>
            </div>

            <div class="flex flex-col gap-6">
                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <h2 class="text-base font-semibold text-zinc-800 dark:text-white">Findings by category</h2>
                    <p class="text-xs text-zinc-400 dark:text-zinc-500">Last 30 days</p>

                    <div class="mt-4 flex flex-col gap-3">
                        @foreach ($categoryBreakdown as $entry)
                            <div class="flex items-center gap-3">
                                <flux:icon :name="$entry['category']->icon()" class="size-4 shrink-0 text-zinc-400 dark:text-zinc-500" />
                                <span class="w-20 shrink-0 text-sm text-zinc-600 dark:text-zinc-300">{{ $entry['category']->label() }}</span>
                                <div class="h-2 flex-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                    <div class="h-full rounded-full {{ $categoryBarColors[$entry['category']->color()] }}" style="width: {{ $entry['percent'] }}%"></div>
                                </div>
                                <span class="w-8 shrink-0 text-right text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $entry['count'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <h2 class="text-base font-semibold text-zinc-800 dark:text-white">Walks per week</h2>

                    <div class="mt-4 flex items-end gap-2">
                        @foreach ($weeklyWalks as $week)
                            <div class="flex flex-1 flex-col items-center gap-1">
                                <div class="flex h-20 w-full items-end justify-center">
                                    <div
                                        class="w-6 rounded-t {{ $week['count'] > 0 ? 'bg-teal-500' : 'bg-teal-500/20' }}"
                                        style="height: {{ $week['count'] > 0 ? $week['percent'].'%' : '2px' }}"
                                        title="{{ $week['count'] }} {{ $week['count'] === 1 ? 'walk' : 'walks' }}"
                                    ></div>
                                </div>
                                <span class="text-[10px] text-zinc-400 dark:text-zinc-500">{{ $week['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Recent walks --}}
        <div>
            <h2 class="text-base font-semibold text-zinc-800 dark:text-white">Recent walks</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($recentWalks as $walk)
                    <a
                        href="{{ route('walks.show', $walk) }}"
                        wire:navigate
                        class="group flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900"
                    >
                        <div class="relative">
                            @if ($cover = $walk->findings->first()?->thumbnail_url)
                                <img src="{{ $cover }}" alt="" loading="lazy" class="aspect-[4/3] w-full object-cover" />
                            @else
                                <div class="flex aspect-[4/3] w-full items-center justify-center bg-teal-500/10 dark:bg-teal-400/10">
                                    <flux:icon name="camera" class="size-10 text-teal-600 dark:text-teal-400" />
                                </div>
                            @endif
                        </div>

                        <div class="flex flex-1 flex-col gap-2 p-4">
                            <div class="truncate text-sm font-semibold text-zinc-800 dark:text-white">{{ $walk->title }}</div>

                            <div class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                <flux:icon name="map-pin" variant="mini" class="size-4 shrink-0" />
                                <span class="truncate">{{ $walk->area }}</span>
                            </div>

                            <div class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                <flux:icon name="calendar" variant="mini" class="size-4 shrink-0" />
                                {{ $walk->walked_on->format('j M Y') }}
                            </div>

                            <div class="mt-auto flex items-center justify-between gap-2 pt-1">
                                <flux:badge :color="$walk->status->color()" size="sm">{{ $walk->status->label() }}</flux:badge>

                                <span class="inline-flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                    <flux:icon name="photo" variant="mini" class="size-4" />
                                    {{ $walk->findings_count }}
                                </span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</div>
