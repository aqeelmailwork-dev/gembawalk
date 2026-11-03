<?php

use App\Models\GembaWalk;
use Livewire\Volt\Component;

new class extends Component {
    public GembaWalk $walk;

    /**
     * Authorize the viewer to see this walk.
     */
    public function mount(GembaWalk $walk): void
    {
        $this->authorize('view', $walk);

        $this->walk = $walk->load('user');
    }

    /**
     * Whether the current user may edit this walk.
     */
    public function canUpdate(): bool
    {
        return auth()->user()->can('update', $this->walk);
    }
}; ?>

<div class="flex flex-col gap-6">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('walks.index') }}" wire:navigate>Walks</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $walk->title }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $walk->title }}</flux:heading>
            <flux:subheading size="lg">{{ $walk->area }}</flux:subheading>
        </div>

        @if ($this->canUpdate())
            <flux:button href="{{ route('walks.edit', $walk) }}" icon="pencil" wire:navigate>Edit</flux:button>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-zinc-500 dark:text-zinc-400">
        <span class="inline-flex items-center gap-1.5">
            <flux:icon name="map-pin" class="size-4" />
            {{ $walk->area }}
        </span>

        <span class="inline-flex items-center gap-1.5">
            <flux:icon name="calendar" class="size-4" />
            {{ $walk->walked_on->format('j M Y') }}
        </span>

        <flux:badge :color="$walk->status->color()">{{ $walk->status->label() }}</flux:badge>

        <span class="inline-flex items-center gap-1.5">
            <flux:avatar :initials="$walk->user->initials()" size="xs" />
            {{ $walk->user->name }}
        </span>
    </div>

    <div class="flex min-h-64 flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed border-zinc-200 p-10 text-center dark:border-zinc-700">
        <flux:icon name="photo" class="size-10 text-zinc-300 dark:text-zinc-600" />
        <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Photos &amp; findings coming soon</p>
    </div>
</div>
