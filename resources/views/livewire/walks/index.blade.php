<?php

use App\Enums\Severity;
use App\Enums\WalkStatus;
use App\Models\GembaWalk;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $walkedBy = '';

    /**
     * Any approved user may browse walks.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', GembaWalk::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedWalkedBy(): void
    {
        $this->resetPage();
    }

    /**
     * Whether the current user may start a walk.
     */
    public function canCreate(): bool
    {
        return $this->viewer()->can('create', GembaWalk::class);
    }

    /**
     * Whether the current user may see everyone's walks.
     */
    public function canViewAll(): bool
    {
        return $this->viewer()->can('walks.view_all');
    }

    /**
     * Users who have at least one walk, for the "walked by" filter.
     */
    public function walkers()
    {
        return User::query()->has('walks')->orderBy('name')->get();
    }

    /**
     * The filtered, paginated list of walks visible to the current user.
     */
    public function walks(): LengthAwarePaginator
    {
        return GembaWalk::query()
            ->visibleTo($this->viewer())
            ->withCount('findings')
            ->with([
                'user',
                'findings' => fn ($query) => $query->select([
                    'id',
                    'gemba_walk_id',
                    'thumbnail_path',
                    'severity',
                    'action_status',
                    'position',
                ]),
            ])
            ->when($this->status === WalkStatus::Draft->value, fn ($query) => $query->where('status', WalkStatus::Draft->value))
            ->when($this->status === WalkStatus::Completed->value, fn ($query) => $query->where('status', WalkStatus::Completed->value))
            ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                $query->where('title', 'like', '%'.$this->search.'%')
                    ->orWhere('area', 'like', '%'.$this->search.'%');
            }))
            ->when($this->walkedBy !== '', fn ($query) => $query->where('user_id', $this->walkedBy))
            ->orderByDesc('walked_on')
            ->orderByDesc('id')
            ->paginate(12);
    }

    /**
     * Non-zero severity counts for a walk, ordered high -> low.
     *
     * @return array<int, array{count: int, label: string, dot: string}>
     */
    public function severityChips(GembaWalk $walk): array
    {
        $counts = $walk->findingsBySeverity();

        $chips = [];

        foreach ([Severity::High, Severity::Medium, Severity::Low] as $severity) {
            $count = $counts[$severity->value] ?? 0;

            if ($count === 0) {
                continue;
            }

            $chips[] = [
                'count' => $count,
                'label' => match ($severity) {
                    Severity::High => 'high',
                    Severity::Medium => 'med',
                    Severity::Low => 'low',
                },
                'dot' => match ($severity->color()) {
                    'red' => 'bg-red-500',
                    'amber' => 'bg-amber-500',
                    'green' => 'bg-green-500',
                    default => 'bg-zinc-400',
                },
            ];
        }

        return $chips;
    }

    private function viewer(): User
    {
        return auth()->user();
    }
}; ?>

@php
    $walks = $this->walks();
@endphp

<div class="flex flex-col gap-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Gemba walks</flux:heading>
            <flux:subheading size="lg">Go see, take photos, capture what you find.</flux:subheading>
        </div>

        @if ($this->canCreate())
            <div class="hidden sm:block">
                <flux:button href="{{ route('walks.create') }}" icon="plus" variant="primary" wire:navigate>New walk</flux:button>
            </div>
        @endif
    </div>

    {{-- Filters --}}
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <flux:radio.group wire:model.live="status" variant="segmented">
            <flux:radio value="all" label="All" />
            <flux:radio value="draft" label="Draft" />
            <flux:radio value="completed" label="Completed" />
        </flux:radio.group>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="sm:w-72">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search title or area" />
            </div>

            @if ($this->canViewAll())
                <div class="sm:w-56">
                    <flux:select wire:model.live="walkedBy" aria-label="Walked by">
                        <flux:select.option value="">Everyone</flux:select.option>

                        @foreach ($this->walkers() as $walker)
                            <flux:select.option :value="$walker->id">{{ $walker->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif
        </div>
    </div>

    @if ($walks->isNotEmpty())
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($walks as $walk)
                <a
                    href="{{ route('walks.show', $walk) }}"
                    wire:navigate
                    class="group flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900"
                >
                    <div class="relative">
                        @if ($cover = $walk->findings->first()?->thumbnail_url)
                            <img src="{{ $cover }}" alt="" loading="lazy" class="aspect-[16/10] w-full object-cover" />
                        @else
                            <div class="flex aspect-[16/10] w-full items-center justify-center bg-teal-500/10 dark:bg-teal-400/10">
                                <flux:icon name="camera" class="size-10 text-teal-600 dark:text-teal-400" />
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-1 flex-col gap-3 p-4">
                        <div class="truncate text-sm font-semibold text-zinc-800 dark:text-white">{{ $walk->title }}</div>

                        <div class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                            <flux:icon name="map-pin" variant="mini" class="size-4 shrink-0" />
                            <span class="truncate">{{ $walk->area }}</span>
                        </div>

                        <div class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                            <flux:icon name="calendar" variant="mini" class="size-4 shrink-0" />
                            {{ $walk->walked_on->format('j M Y') }}
                        </div>

                        <div class="flex items-center gap-2">
                            <flux:badge :color="$walk->status->color()" size="sm">{{ $walk->status->label() }}</flux:badge>
                        </div>

                        @if (! empty($chips = $this->severityChips($walk)))
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                @foreach ($chips as $chip)
                                    <span class="inline-flex items-center gap-1">
                                        <span class="size-1.5 rounded-full {{ $chip['dot'] }}"></span>
                                        {{ $chip['count'] }} {{ $chip['label'] }}
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        <div class="mt-auto flex items-center justify-between gap-2 pt-1 text-xs text-zinc-500 dark:text-zinc-400">
                            <span class="inline-flex items-center gap-1.5">
                                <flux:icon name="photo" variant="mini" class="size-4" />
                                {{ $walk->findings_count }} {{ $walk->findings_count === 1 ? 'photo' : 'photos' }}
                            </span>

                            <span class="inline-flex items-center gap-1.5">
                                {{ $walk->openActionsCount() }} open
                            </span>

                            @if ($this->canViewAll())
                                <span class="inline-flex min-w-0 items-center gap-1.5">
                                    <flux:avatar :initials="$walk->user->initials()" size="xs" />
                                    <span class="truncate">{{ $walk->user->name }}</span>
                                </span>
                            @endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>

        <div>
            <flux:pagination :paginator="$walks" />
        </div>
    @else
        <flux:card>
            <div class="flex flex-col items-center justify-center gap-4 py-16 text-center">
                <div class="flex size-16 items-center justify-center rounded-full bg-teal-500/10 dark:bg-teal-400/10">
                    <flux:icon name="camera" class="size-8 text-teal-600 dark:text-teal-400" />
                </div>

                <div>
                    <div class="text-lg font-semibold text-zinc-800 dark:text-white">No walks yet</div>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Start your first gemba walk to capture findings on the floor.</p>
                </div>

                @if ($this->canCreate())
                    <flux:button href="{{ route('walks.create') }}" variant="primary" icon="plus" wire:navigate>Start a walk</flux:button>
                @endif
            </div>
        </flux:card>
    @endif

    {{-- Mobile floating action button --}}
    @if ($this->canCreate())
        <a
            href="{{ route('walks.create') }}"
            wire:navigate
            aria-label="New walk"
            class="fixed right-6 z-20 inline-flex size-14 items-center justify-center rounded-full bg-teal-600 text-white shadow-lg shadow-teal-600/30 transition hover:bg-teal-500 sm:hidden"
            style="bottom: calc(1.5rem + env(safe-area-inset-bottom))"
        >
            <flux:icon name="plus" class="size-6" />
        </a>
    @endif
</div>
