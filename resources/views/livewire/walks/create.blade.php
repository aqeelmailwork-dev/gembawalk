<?php

use App\Enums\WalkStatus;
use App\Livewire\Concerns\WithWalkForm;
use App\Models\ChecklistCategory;
use App\Models\GembaWalk;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Volt\Component;

new class extends Component {
    use WithWalkForm;

    /**
     * Authorize the user to create a walk and default the walked-on date.
     */
    public function mount(): void
    {
        $this->authorize('create', GembaWalk::class);

        $this->walked_on = now()->toDateString();
    }

    /**
     * Categories a walk can be started for: active, with at least one active check.
     */
    public function categories(): Collection
    {
        return ChecklistCategory::query()
            ->active()
            ->ordered()
            ->withCount(['items as checks_count' => fn ($query) => $query->where('is_active', true)])
            ->get()
            ->filter(fn (ChecklistCategory $category) => $category->checks_count > 0)
            ->values();
    }

    /**
     * Start a draft walk for the chosen category - nothing to type.
     */
    public function start(int $categoryId): void
    {
        $this->authorize('create', GembaWalk::class);

        $category = $this->categories()->firstWhere('id', $categoryId);

        abort_if($category === null, 404);

        $walk = new GembaWalk;

        $walk->title = "{$category->name} walk";
        $walk->area = $category->name;
        $walk->walked_on = now()->toDateString();
        $walk->user_id = auth()->id();
        $walk->checklist_category_id = $category->id;
        $walk->status = WalkStatus::Draft->value;
        $walk->save();

        Flux::toast(variant: 'success', text: "{$category->name} walk started");

        $this->redirectRoute('walks.show', ['walk' => $walk]);
    }

    /**
     * Fallback when no checklist categories exist yet: a typed walk.
     */
    public function save(): void
    {
        abort_if($this->categories()->isNotEmpty(), 403);

        $validated = $this->validate();

        $walk = new GembaWalk;

        $walk->fill($validated);
        $walk->user_id = auth()->id();
        $walk->status = WalkStatus::Draft->value;
        $walk->save();

        Flux::toast(variant: 'success', text: 'Walk started - add your first photo');

        $this->redirectRoute('walks.show', ['walk' => $walk]);
    }
}; ?>

@php
    $categories = $this->categories();
@endphp

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Start a walk</flux:heading>
        <flux:subheading size="lg">
            @if ($categories->isNotEmpty())
                What are you checking today? Tap one to begin.
            @else
                Start a gemba walk and capture what you find.
            @endif
        </flux:subheading>
    </div>

    @if ($categories->isNotEmpty())
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($categories as $category)
                <button
                    type="button"
                    wire:key="start-{{ $category->id }}"
                    wire:click="start({{ $category->id }})"
                    wire:loading.attr="disabled"
                    class="group flex min-h-24 items-center gap-4 rounded-xl border border-zinc-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-teal-300 hover:shadow-md disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
                >
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-700 transition group-hover:bg-teal-600 group-hover:text-white dark:bg-teal-500/10 dark:text-teal-300">
                        <flux:icon name="clipboard-document-check" class="size-6" wire:loading.remove wire:target="start({{ $category->id }})" />
                        <flux:icon.loading class="size-6" wire:loading wire:target="start({{ $category->id }})" />
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="font-semibold text-zinc-900 dark:text-white">{{ $category->name }}</div>
                        @if ($category->description)
                            <div class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $category->description }}</div>
                        @endif
                        <div class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">{{ $category->checks_count }} {{ Str::plural('check', $category->checks_count) }}</div>
                    </div>

                    <flux:icon name="chevron-right" class="size-5 shrink-0 text-zinc-300 transition group-hover:translate-x-0.5 group-hover:text-teal-600 dark:text-zinc-600" />
                </button>
            @endforeach
        </div>

        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            The walk is dated today ({{ now()->format('j M Y') }}). You can rename it or add a purpose later from Edit.
        </p>
    @else
        @can('checklists.manage')
            <flux:callout icon="information-circle" variant="secondary">
                <flux:callout.text>No checklist categories yet - <flux:link :href="route('admin.checklists')" wire:navigate>set them up</flux:link> so people can start a walk with one tap.</flux:callout.text>
            </flux:callout>
        @endcan

        <flux:card class="max-w-2xl">
            <form wire:submit="save" class="space-y-6">
                @include('livewire.walks.partials.form')

                <div class="flex items-center justify-end gap-2">
                    <flux:button href="{{ route('walks.index') }}" variant="ghost" wire:navigate>Cancel</flux:button>
                    <flux:button variant="primary" type="submit">Start walk</flux:button>
                </div>
            </form>
        </flux:card>
    @endif
</div>
