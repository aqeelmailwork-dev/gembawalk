<?php

use App\Enums\ActionStatus;
use App\Enums\FindingCategory;
use App\Enums\Severity;
use App\Models\Finding;
use App\Models\GembaWalk;
use App\Services\FindingPhotoProcessor;
use Flux\Flux;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public GembaWalk $walk;

    public $capture = null;

    public array $gallery = [];

    /**
     * Authorize the viewer to see this walk.
     */
    public function mount(GembaWalk $walk): void
    {
        $this->authorize('view', $walk);

        $this->walk = $walk->load(['user', 'findings']);
    }

    /**
     * Whether the current user may edit this walk.
     */
    public function canUpdate(): bool
    {
        return auth()->user()->can('update', $this->walk);
    }

    /**
     * Whether the current user may add a finding to this walk.
     */
    public function canCreate(): bool
    {
        return auth()->user()->can('create', [Finding::class, $this->walk]);
    }

    /**
     * React to a single photo captured with the camera.
     */
    public function updatedCapture(): void
    {
        $this->addPhotos([$this->capture], 'capture');
    }

    /**
     * React to one or more photos picked from the gallery.
     */
    public function updatedGallery(): void
    {
        $this->addPhotos($this->gallery, 'gallery');
    }

    /**
     * Validate and store uploaded photos as new, low-severity findings.
     */
    public function addPhotos(array $files, string $field): void
    {
        $this->authorize('create', [Finding::class, $this->walk]);

        $rules = $field === 'capture'
            ? ['capture' => FindingPhotoProcessor::rules()['photo']]
            : [
                'gallery' => ['array', 'max:20'],
                'gallery.*' => FindingPhotoProcessor::rules()['photo'],
            ];

        $messages = $field === 'capture'
            ? ['capture.mimes' => FindingPhotoProcessor::messages()['photo.mimes']]
            : ['gallery.*.mimes' => FindingPhotoProcessor::messages()['photo.mimes']];

        $this->validate($rules, $messages);

        $firstId = null;

        foreach ($files as $file) {
            $paths = app(FindingPhotoProcessor::class)->store($file, $this->walk);

            $finding = new Finding;

            $finding->gemba_walk_id = $this->walk->getKey();
            $finding->photo_path = $paths['photo_path'];
            $finding->thumbnail_path = $paths['thumbnail_path'];
            $finding->category = FindingCategory::Other;
            $finding->severity = Severity::Low;
            $finding->action_status = ActionStatus::Open;
            $finding->position = $this->nextPosition();
            $finding->save();

            $firstId ??= $finding->getKey();
        }

        $this->reset('capture', 'gallery');

        Flux::toast(variant: 'success', text: 'Photo added - describe what you found');

        $this->dispatch('finding-added', id: $firstId);
    }

    /**
     * Flip a finding's action status between open and done.
     */
    public function toggleAction(int $id): void
    {
        $finding = $this->walk->findings()->findOrFail($id);

        $this->authorize('updateAction', $finding);

        $finding->action_status = $finding->action_status === ActionStatus::Open
            ? ActionStatus::Done
            : ActionStatus::Open;

        $finding->save();

        Flux::toast(
            variant: 'success',
            text: $finding->action_status === ActionStatus::Done ? 'Action marked done' : 'Action reopened',
        );
    }

    /**
     * The next position in this walk's finding sequence.
     */
    private function nextPosition(): int
    {
        return ($this->walk->findings()->max('position') ?? 0) + 1;
    }
}; ?>

@php
    $findings = $walk->findings;
    $openActions = $walk->openActionsCount();
    $highSeverity = $walk->findingsBySeverity()['high'];
@endphp

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

    @if ($walk->summary)
        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $walk->summary }}</p>
    @endif

    {{-- Stat strip --}}
    <div class="grid grid-cols-3 gap-3">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-2xl font-semibold text-zinc-800 dark:text-white">{{ $findings->count() }}</div>
            <div class="mt-0.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">Findings</div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-2xl font-semibold text-zinc-800 dark:text-white">{{ $openActions }}</div>
            <div class="mt-0.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">Open actions</div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-2xl font-semibold {{ $highSeverity > 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-800 dark:text-white' }}">{{ $highSeverity }}</div>
            <div class="mt-0.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">High severity</div>
        </div>
    </div>

    {{-- Capture zone --}}
    @if ($this->canCreate())
        <div
            x-data="{ uploading: false, progress: 0 }"
            x-on:livewire-upload-start="uploading = true"
            x-on:livewire-upload-finish="uploading = false"
            x-on:livewire-upload-error="uploading = false"
            x-on:livewire-upload-progress="progress = $event.detail.progress"
            class="rounded-xl border-2 border-dashed border-teal-300 bg-teal-50/50 p-6 text-center dark:border-teal-700 dark:bg-teal-950/20"
        >
            <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-teal-500/10 dark:bg-teal-400/10">
                <flux:icon name="camera" class="size-7 text-teal-600 dark:text-teal-400" />
            </div>

            <div class="mt-4 text-base font-semibold text-zinc-800 dark:text-white">Capture what you see</div>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Add photos straight from the floor, then describe each finding.</p>

            <div class="mx-auto mt-5 grid max-w-sm grid-cols-2 gap-3">
                <label class="inline-flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-lg bg-teal-600 px-4 text-sm font-medium text-white shadow-sm transition hover:bg-teal-500">
                    <flux:icon name="camera" class="size-4" />
                    Take photo
                    <input type="file" class="sr-only" accept="image/*" capture="environment" wire:model="capture">
                </label>

                <label class="inline-flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-lg bg-zinc-100 px-4 text-sm font-medium text-zinc-700 transition hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700">
                    <flux:icon name="photo" class="size-4" />
                    From gallery
                    <input type="file" class="sr-only" accept="image/jpeg,image/png,image/webp" multiple wire:model="gallery">
                </label>
            </div>

            <div x-show="uploading" x-cloak class="mt-4">
                <div class="mx-auto h-2 w-full max-w-sm overflow-hidden rounded-full bg-teal-200/60 dark:bg-teal-900/60">
                    <div class="h-full bg-teal-500 transition-all" :style="`width: ${progress}%`"></div>
                </div>
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Uploading&hellip; <span x-text="progress"></span>%</p>
            </div>

            @error('capture')
                <p class="mt-3 text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror

            @error('gallery')
                <p class="mt-3 text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror

            @error('gallery.*')
                <p class="mt-3 text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>
    @endif

    {{-- Findings list --}}
    @if ($findings->isNotEmpty())
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($findings as $finding)
                <div class="flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="relative p-3 pb-0">
                        @if ($finding->thumbnail_url)
                            <img src="{{ $finding->thumbnail_url }}" alt="Finding photo" loading="lazy" class="aspect-[4/3] w-full rounded-lg object-cover" />
                        @else
                            <div class="flex aspect-[4/3] w-full items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon name="photo" class="size-8 text-zinc-300 dark:text-zinc-600" />
                            </div>
                        @endif

                        <span class="absolute left-5 top-5 rounded-md bg-zinc-900/70 px-2 py-0.5 text-xs font-medium text-white">#{{ $finding->position }}</span>
                    </div>

                    <div class="flex flex-1 flex-col gap-2 p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:badge :color="$finding->category->color()" :icon="$finding->category->icon()">{{ $finding->category->label() }}</flux:badge>
                            <flux:badge :color="$finding->severity->color()">{{ $finding->severity->label() }}</flux:badge>
                        </div>

                        @if ($finding->observation)
                            <p class="text-sm text-zinc-700 line-clamp-3 dark:text-zinc-300">{{ $finding->observation }}</p>
                        @else
                            <p class="inline-flex items-center gap-1.5 text-sm font-medium text-amber-600 dark:text-amber-400">
                                <flux:icon name="exclamation-triangle" variant="mini" class="size-4" />
                                Needs description
                            </p>
                        @endif

                        @if ($finding->action_required)
                            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                                <span class="font-medium">Action:</span> {{ $finding->action_required }}
                            </p>
                        @endif

                        @if ($finding->action_owner || $finding->due_on)
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                @if ($finding->action_owner)
                                    <span class="inline-flex items-center gap-1">
                                        <flux:icon name="user" variant="mini" class="size-4" />
                                        {{ $finding->action_owner }}
                                    </span>
                                @endif

                                @if ($finding->due_on)
                                    <span class="inline-flex items-center gap-1 {{ $finding->isOverdue() ? 'text-red-600 dark:text-red-400' : '' }}">
                                        <flux:icon name="calendar" variant="mini" class="size-4" />
                                        {{ $finding->due_on->format('j M Y') }}

                                        @if ($finding->isOverdue())
                                            <flux:badge color="red" size="sm">Overdue</flux:badge>
                                        @endif
                                    </span>
                                @endif
                            </div>
                        @endif

                        <div class="mt-auto pt-2">
                            @can('updateAction', $finding)
                                @if ($finding->action_status === ActionStatus::Open)
                                    <flux:button size="sm" color="amber" icon="clock" wire:click="toggleAction({{ $finding->id }})">Open</flux:button>
                                @else
                                    <flux:button size="sm" color="green" icon="check" wire:click="toggleAction({{ $finding->id }})">Done</flux:button>
                                @endif
                            @else
                                <flux:badge :color="$finding->action_status->color()">{{ $finding->action_status->label() }}</flux:badge>
                            @endcan
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <flux:card>
            <div class="flex flex-col items-center justify-center gap-3 py-12 text-center">
                <flux:icon name="photo" class="size-8 text-zinc-300 dark:text-zinc-600" />
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $this->canCreate() ? 'No findings yet - take your first photo above.' : 'No findings were recorded on this walk.' }}
                </p>
            </div>
        </flux:card>
    @endif

    {{-- Part 2: edit/delete finding modals, complete/reopen, lightbox --}}
</div>
