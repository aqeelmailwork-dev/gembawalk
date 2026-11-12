<?php

use App\Models\GembaWalk;
use App\Support\WalkReport;
use Livewire\Volt\Component;

new class extends Component {
    public GembaWalk $walk;

    private WalkReport $report;

    public function mount(GembaWalk $walk): void
    {
        $this->authorize('view', $walk);

        $this->walk = $walk;

        $this->report = new WalkReport($this->walk);
    }

    public function report(): WalkReport
    {
        return $this->report;
    }
}; ?>

@php
    $report = $this->report();
    $walk = $report->walk;
    $totals = $report->totals();

    $categoryBarColors = [
        'red' => 'bg-red-500',
        'blue' => 'bg-blue-500',
        'violet' => 'bg-violet-500',
        'amber' => 'bg-amber-500',
        'cyan' => 'bg-cyan-500',
        'zinc' => 'bg-zinc-500',
    ];

    $severityBarColors = [
        'high' => 'bg-red-500',
        'medium' => 'bg-amber-500',
        'low' => 'bg-green-500',
    ];
@endphp

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    {{-- Top bar --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('walks.index')" wire:navigate>Walks</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('walks.show', $walk)" wire:navigate>{{ $walk->title }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>Report</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex items-center gap-2 print:hidden">
            @can('exportReport', $walk)
                <flux:button :href="route('walks.report.pdf', $walk)" variant="primary" icon="arrow-down-tray">Download PDF</flux:button>
            @endcan

            <flux:button icon="printer" x-on:click="window.print()">Print</flux:button>
        </div>
    </div>

    {{-- Report header band --}}
    <div class="rounded-2xl bg-gradient-to-br from-teal-600 to-teal-800 p-6 text-white shadow-sm break-inside-avoid sm:p-8">
        <p class="text-xs font-semibold uppercase tracking-wider text-teal-100">Gemba walk report</p>
        <h1 class="mt-2 text-2xl font-semibold sm:text-3xl">{{ $walk->title }}</h1>

        <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-teal-50">
            <span class="inline-flex items-center gap-1.5">
                <flux:icon name="map-pin" class="size-4" />
                {{ $walk->area }}
            </span>

            <span class="inline-flex items-center gap-1.5">
                <flux:icon name="calendar" class="size-4" />
                {{ $walk->walked_on->format('j M Y') }}
            </span>

            <span class="inline-flex items-center gap-1.5">
                <flux:icon name="user" class="size-4" />
                {{ $walk->user->name }}
            </span>

            <span class="rounded-full bg-white/20 px-2.5 py-0.5 text-xs font-medium">{{ $walk->status->label() }}</span>

            <span class="inline-flex items-center gap-1.5 text-teal-100">
                <flux:icon name="clock" class="size-4" />
                Generated {{ now()->format('j M Y') }}
            </span>
        </div>
    </div>

    {{-- KPI row --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 break-inside-avoid">
            <div class="text-2xl font-semibold text-zinc-800 dark:text-white">{{ $totals['findings'] }}</div>
            <div class="mt-0.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">Findings</div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 break-inside-avoid">
            <div class="text-2xl font-semibold {{ $totals['high'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-800 dark:text-white' }}">{{ $totals['high'] }}</div>
            <div class="mt-0.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">High severity</div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 break-inside-avoid">
            <div class="text-2xl font-semibold {{ $totals['open_actions'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-800 dark:text-white' }}">{{ $totals['open_actions'] }}</div>
            <div class="mt-0.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">Open actions</div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 break-inside-avoid">
            <div class="text-2xl font-semibold {{ $totals['overdue'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-800 dark:text-white' }}">{{ $totals['overdue'] }}</div>
            <div class="mt-0.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">Overdue</div>
        </div>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 break-inside-avoid">
        <div class="flex items-center justify-between text-sm">
            <span class="font-medium text-zinc-700 dark:text-zinc-200">Actions completed</span>
            <span class="font-semibold text-zinc-800 dark:text-white">{{ $report->actionCompletionPercent() }}%</span>
        </div>

        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
            <div class="h-full rounded-full bg-teal-500 transition-all" style="width: {{ $report->actionCompletionPercent() }}%"></div>
        </div>
    </div>

    {{-- Checklist results --}}
    @php($checklist = $report->checklistTotals())
    @if ($checklist['answered'] > 0)
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 break-inside-avoid">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="flex items-center gap-2 text-base font-semibold text-zinc-800 dark:text-white">
                    <flux:icon name="clipboard-document-check" class="size-4 text-teal-600 dark:text-teal-400" />
                    Checklist results
                </h2>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">
                    <span class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ $checklist['score'] }}%</span>
                    passed &middot; {{ $checklist['yes'] }} Yes &middot; <span @class(['font-medium text-red-600 dark:text-red-400' => $checklist['no'] > 0])>{{ $checklist['no'] }} No</span>
                </div>
            </div>

            <div class="mt-4 space-y-3">
                @foreach ($report->checklistByCategory() as $group)
                    <div>
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $group['name'] }}</span>
                            <span class="text-zinc-500 dark:text-zinc-400">{{ $group['yes'] }}/{{ $group['answered'] }} &middot; {{ $group['score'] }}%</span>
                        </div>
                        <div class="flex h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div class="h-full bg-green-500" style="width: {{ $group['score'] }}%"></div>
                            <div class="h-full bg-red-500" style="width: {{ 100 - $group['score'] }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($report->checklistFailures()->isNotEmpty())
                <h3 class="mt-5 text-sm font-semibold text-zinc-800 dark:text-white">Needs attention</h3>
                <ul class="mt-2 divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($report->checklistFailures() as $failure)
                        <li class="flex items-start gap-3 py-2.5">
                            @if ($failure->finding?->thumbnail_url)
                                <img src="{{ $failure->finding->thumbnail_url }}" alt="" class="size-12 shrink-0 rounded-md object-cover">
                            @else
                                <div class="flex size-12 shrink-0 items-center justify-center rounded-md bg-red-50 text-red-500 dark:bg-red-500/10">
                                    <flux:icon name="x-circle" class="size-5" />
                                </div>
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="text-sm text-zinc-800 dark:text-zinc-100"><span class="text-zinc-500 dark:text-zinc-400">{{ $failure->category_name }}:</span> {{ $failure->question }}</div>
                                @if ($failure->note)
                                    <div class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $failure->note }}</div>
                                @endif
                            </div>
                            <flux:badge size="sm" color="red">No</flux:badge>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if ($report->findings->isNotEmpty())
        {{-- Category and severity --}}
        <div class="grid gap-6 md:grid-cols-2">
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 break-inside-avoid">
                <h2 class="text-base font-semibold text-zinc-800 dark:text-white">Findings by category</h2>

                <div class="mt-4 flex flex-col gap-3">
                    @foreach ($report->byCategory() as $entry)
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

            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 break-inside-avoid">
                <h2 class="text-base font-semibold text-zinc-800 dark:text-white">Findings by severity</h2>

                <div class="mt-4 flex h-4 w-full overflow-hidden rounded-full">
                    @foreach ($report->bySeverity() as $entry)
                        @if ($entry['count'] > 0)
                            <div class="{{ $severityBarColors[$entry['severity']->value] }}" style="width: {{ $entry['percent'] }}%"></div>
                        @endif
                    @endforeach
                </div>

                <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2">
                    @foreach ($report->bySeverity() as $entry)
                        <div class="flex items-center gap-2 text-sm">
                            <span class="size-2.5 rounded-full {{ $severityBarColors[$entry['severity']->value] }}"></span>
                            <span class="text-zinc-600 dark:text-zinc-300">{{ $entry['severity']->label() }}</span>
                            <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $entry['count'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Top priorities --}}
        @if ($report->priorities()->isNotEmpty())
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 break-inside-avoid">
                <h2 class="text-base font-semibold text-zinc-800 dark:text-white">Top priorities</h2>

                <div class="mt-4 flex flex-col gap-4">
                    @foreach ($report->priorities() as $finding)
                        <div class="flex gap-4">
                            <div class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                @if ($finding->thumbnail_url)
                                    <img src="{{ $finding->thumbnail_url }}" alt="Finding photo" class="size-full object-cover" loading="lazy" />
                                @else
                                    <flux:icon name="photo" class="size-6 text-zinc-300 dark:text-zinc-600" />
                                @endif
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs font-medium text-zinc-400 dark:text-zinc-500">#{{ $finding->position }}</span>
                                    <flux:badge :color="$finding->category->color()" :icon="$finding->category->icon()">{{ $finding->category->label() }}</flux:badge>
                                    <flux:badge :color="$finding->severity->color()">{{ $finding->severity->label() }}</flux:badge>
                                </div>

                                <p class="mt-1 text-sm text-zinc-700 line-clamp-2 dark:text-zinc-300">{{ $finding->observation }}</p>

                                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    @if ($finding->action_owner)
                                        <span class="inline-flex items-center gap-1">
                                            <flux:icon name="user" variant="mini" class="size-3.5" />
                                            {{ $finding->action_owner }}
                                        </span>
                                    @endif

                                    @if ($finding->due_on)
                                        <span class="inline-flex items-center gap-1 {{ $finding->isOverdue() ? 'text-red-600 dark:text-red-400' : '' }}">
                                            <flux:icon name="calendar" variant="mini" class="size-3.5" />
                                            {{ $finding->due_on->format('j M Y') }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Takeaways --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 break-inside-avoid">
            <h2 class="flex items-center gap-2 text-base font-semibold text-zinc-800 dark:text-white">
                <flux:icon name="light-bulb" class="size-4 text-teal-600 dark:text-teal-400" />
                Takeaways
            </h2>

            @if ($walk->summary)
                <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">{{ $walk->summary }}</p>
            @else
                <p class="mt-2 text-sm text-zinc-400 dark:text-zinc-500">No summary recorded.</p>
            @endif
        </div>

        {{-- Photo evidence --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 break-inside-avoid">
            <h2 class="text-base font-semibold text-zinc-800 dark:text-white">Photo evidence</h2>

            <div class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-3">
                @foreach ($report->findings as $finding)
                    <div class="break-inside-avoid">
                        <div class="relative">
                            <div class="flex aspect-[4/3] w-full items-center justify-center overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                @if ($finding->thumbnail_url)
                                    <img src="{{ $finding->thumbnail_url }}" alt="Finding photo" class="size-full object-cover" loading="lazy" />
                                @else
                                    <flux:icon name="photo" class="size-8 text-zinc-300 dark:text-zinc-600" />
                                @endif
                            </div>

                            <span class="absolute left-2 top-2 rounded-md bg-zinc-900/70 px-2 py-0.5 text-xs font-medium text-white">#{{ $finding->position }}</span>
                        </div>

                        <div class="mt-2">
                            <flux:badge :color="$finding->category->color()" size="sm">{{ $finding->category->label() }}</flux:badge>
                        </div>

                        @if ($finding->observation)
                            <p class="mt-1 text-sm text-zinc-600 line-clamp-2 dark:text-zinc-300">{{ $finding->observation }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Action plan --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 break-inside-avoid">
            <h2 class="text-base font-semibold text-zinc-800 dark:text-white">Action plan</h2>

            @if ($report->actionPlan()->isNotEmpty())
                {{-- Desktop table --}}
                <div class="mt-4 hidden md:block">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>#</flux:table.column>
                            <flux:table.column>Finding</flux:table.column>
                            <flux:table.column>Action required</flux:table.column>
                            <flux:table.column>Owner</flux:table.column>
                            <flux:table.column>Due</flux:table.column>
                            <flux:table.column>Status</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($report->actionPlan() as $finding)
                                <flux:table.row :key="$finding->id">
                                    <flux:table.cell>#{{ $finding->position }}</flux:table.cell>
                                    <flux:table.cell class="max-w-64 truncate">{{ $finding->observation }}</flux:table.cell>
                                    <flux:table.cell class="max-w-72 truncate">{{ $finding->action_required }}</flux:table.cell>
                                    <flux:table.cell>{{ $finding->action_owner ?? '—' }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($finding->due_on)
                                            <span class="{{ $finding->isOverdue() ? 'font-medium text-red-600 dark:text-red-400' : '' }}">
                                                {{ $finding->due_on->format('j M Y') }}
                                                @if ($finding->isOverdue())
                                                    <span class="ml-1">Overdue</span>
                                                @endif
                                            </span>
                                        @else
                                            —
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge :color="$finding->action_status->color()">{{ $finding->action_status->label() }}</flux:badge>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>

                {{-- Mobile stacked cards --}}
                <div class="mt-4 flex flex-col gap-3 md:hidden">
                    @foreach ($report->actionPlan() as $finding)
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs font-medium text-zinc-400 dark:text-zinc-500">#{{ $finding->position }}</span>
                                <flux:badge :color="$finding->action_status->color()">{{ $finding->action_status->label() }}</flux:badge>
                            </div>

                            <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">{{ $finding->observation }}</p>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                <span class="font-medium">Action:</span> {{ $finding->action_required }}
                            </p>

                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                @if ($finding->action_owner)
                                    <span>{{ $finding->action_owner }}</span>
                                @endif

                                @if ($finding->due_on)
                                    <span class="{{ $finding->isOverdue() ? 'text-red-600 dark:text-red-400' : '' }}">
                                        {{ $finding->due_on->format('j M Y') }}
                                        @if ($finding->isOverdue())
                                            · Overdue
                                        @endif
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-2 text-sm text-zinc-400 dark:text-zinc-500">No actions were recorded.</p>
            @endif
        </div>
    @else
        <flux:card>
            <div class="flex flex-col items-center justify-center gap-3 py-12 text-center">
                <flux:icon name="photo" class="size-8 text-zinc-300 dark:text-zinc-600" />
                <p class="text-sm text-zinc-500 dark:text-zinc-400">No findings were recorded on this walk.</p>
            </div>
        </flux:card>
    @endif
</div>

<style>
    @media print {
        body {
            display: block !important;
            background: #fff !important;
        }

        [data-flux-sidebar],
        [data-flux-header],
        [data-flux-toast] {
            display: none !important;
        }

        [data-flux-main] {
            padding: 0 !important;
            margin: 0 !important;
            max-width: none !important;
            background: #fff !important;
        }
    }
</style>
