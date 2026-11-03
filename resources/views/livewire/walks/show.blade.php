<?php

use App\Enums\ActionStatus;
use App\Enums\FindingCategory;
use App\Enums\Severity;
use App\Models\Finding;
use App\Models\GembaWalk;
use App\Services\FindingPhotoProcessor;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public GembaWalk $walk;

    public $capture = null;

    public array $gallery = [];

    public ?int $editingId = null;

    public string $category = 'other';

    public string $severity = 'low';

    public string $observation = '';

    public string $actionRequired = '';

    public string $actionOwner = '';

    public ?string $dueOn = null;

    public $replacement = null;

    public ?int $deletingId = null;

    public ?int $lightboxId = null;

    public string $completionSummary = '';

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
     * Open the edit modal, prefilled from the given finding.
     */
    public function editFinding(int $id): void
    {
        $finding = $this->findFinding($id);

        $this->authorize('update', $finding);

        $this->editingId = $finding->id;
        $this->category = $finding->category->value;
        $this->severity = $finding->severity->value;
        $this->observation = $finding->observation ?? '';
        $this->actionRequired = $finding->action_required ?? '';
        $this->actionOwner = $finding->action_owner ?? '';
        $this->dueOn = $finding->due_on?->format('Y-m-d');
        $this->replacement = null;

        $this->modal('edit-finding')->show();
    }

    /**
     * Persist the edited finding.
     */
    public function saveFinding(): void
    {
        $finding = $this->findFinding($this->editingId);

        $this->authorize('update', $finding);

        $validated = $this->validate([
            'category' => ['required', Rule::enum(FindingCategory::class)],
            'severity' => ['required', Rule::enum(Severity::class)],
            'observation' => ['required', 'string', 'max:2000'],
            'actionRequired' => ['nullable', 'string', 'max:2000'],
            'actionOwner' => ['nullable', 'string', 'max:120'],
            'dueOn' => ['nullable', 'date', 'after_or_equal:' . $this->walk->walked_on->format('Y-m-d')],
        ]);

        $finding->category = $validated['category'];
        $finding->severity = $validated['severity'];
        $finding->observation = $validated['observation'];
        $finding->action_required = $validated['actionRequired'] ?: null;
        $finding->action_owner = $validated['actionOwner'] ?: null;
        $finding->due_on = $validated['dueOn'] ?: null;
        $finding->save();

        $this->modal('edit-finding')->close();

        Flux::toast(variant: 'success', text: 'Finding saved');
    }

    /**
     * Replace the finding's photo when a new file is picked.
     */
    public function updatedReplacement(): void
    {
        if (! $this->editingId) {
            return;
        }

        $finding = $this->findFinding($this->editingId);

        $this->authorize('update', $finding);

        $this->validate(
            ['replacement' => FindingPhotoProcessor::rules()['photo']],
            ['replacement.mimes' => FindingPhotoProcessor::messages()['photo.mimes']],
        );

        app(FindingPhotoProcessor::class)->replace($finding, $this->replacement);

        $this->reset('replacement');

        Flux::toast(variant: 'success', text: 'Photo replaced');
    }

    /**
     * Stage a finding for deletion and show the confirmation modal.
     */
    public function confirmDelete(int $id): void
    {
        $this->findFinding($id);

        $this->deletingId = $id;

        $this->modal('delete-finding')->show();
    }

    /**
     * Delete the staged finding and renumber the remaining positions.
     */
    public function deleteFinding(): void
    {
        $finding = $this->findFinding($this->deletingId);

        $this->authorize('delete', $finding);

        $finding->delete();

        $this->renumberPositions();

        $this->modal('delete-finding')->close();

        Flux::toast(variant: 'success', text: 'Finding deleted');
    }

    /**
     * Open the full-size lightbox for a finding.
     */
    public function openLightbox(int $id): void
    {
        $this->authorize('view', $this->walk);

        $finding = $this->findFinding($id);

        $this->lightboxId = $finding->id;

        $this->modal('lightbox')->show();
    }

    /**
     * Open the complete-walk modal, prefilled from the walk summary.
     */
    public function openComplete(): void
    {
        $this->authorize('complete', $this->walk);

        $this->completionSummary = $this->walk->summary ?? '';

        $this->modal('complete-walk')->show();
    }

    /**
     * Close the complete-walk modal and jump straight to a finding editor.
     */
    public function fixFinding(int $id): void
    {
        $this->modal('complete-walk')->close();

        $this->editFinding($id);
    }

    /**
     * Complete the walk, refusing to continue while findings lack descriptions.
     */
    public function completeWalk(): void
    {
        $this->authorize('complete', $this->walk);

        $undescribed = $this->walk->findings->filter(fn (Finding $finding) => blank($finding->observation));

        if ($undescribed->isNotEmpty()) {
            $this->addError('completionSummary', 'Every finding needs a description before the walk can be completed.');

            return;
        }

        $validated = $this->validate([
            'completionSummary' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->walk->summary = $validated['completionSummary'] ?: null;
        $this->walk->save();
        $this->walk->markCompleted();

        $this->modal('complete-walk')->close();

        Flux::toast(variant: 'success', text: 'Walk completed');
    }

    /**
     * Reopen a completed walk back to draft.
     */
    public function reopenWalk(): void
    {
        $this->authorize('reopen', $this->walk);

        $this->walk->reopen();

        Flux::toast(variant: 'success', text: 'Walk reopened');
    }

    /**
     * Fetch a finding that belongs to this walk, or 404.
     */
    private function findFinding(?int $id): Finding
    {
        return $this->walk->findings()->findOrFail($id);
    }

    /**
     * Reassign consecutive positions to the remaining findings.
     */
    private function renumberPositions(): void
    {
        $this->walk->findings()->get()->each(function (Finding $finding, int $index) {
            $position = $index + 1;

            if ($finding->position !== $position) {
                $finding->position = $position;
                $finding->save();
            }
        });
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
    $canComplete = auth()->user()->can('complete', $walk);
    $canReopen = auth()->user()->can('reopen', $walk);
    $undescribedFindings = $findings->filter(fn ($f) => blank($f->observation));
    $editing = $editingId ? $findings->firstWhere('id', $editingId) : null;
    $lightboxFinding = $lightboxId ? $findings->firstWhere('id', $lightboxId) : null;
    $reportLabel = $walk->isCompleted() ? 'Report' : 'Preview report';
    $reportVariant = $walk->isCompleted() ? 'primary' : 'outline';
@endphp

<div class="flex flex-col gap-6" x-on:finding-added.window="$wire.editFinding($event.detail.id)">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('walks.index') }}" wire:navigate>Walks</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $walk->title }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $walk->title }}</flux:heading>
            <flux:subheading size="lg">{{ $walk->area }}</flux:subheading>
        </div>

        {{-- Desktop actions --}}
        <div class="hidden shrink-0 items-center gap-2 sm:flex">
            <flux:button :href="route('walks.report', $walk)" icon="document-chart-bar" :variant="$reportVariant" wire:navigate>{{ $reportLabel }}</flux:button>

            @if ($this->canUpdate())
                <flux:button href="{{ route('walks.edit', $walk) }}" icon="pencil" wire:navigate>Edit</flux:button>
            @endif

            @if ($canReopen)
                <flux:button icon="arrow-uturn-left" wire:click="reopenWalk">Reopen</flux:button>
            @endif

            @if ($canComplete)
                <flux:button variant="primary" icon="check-circle" wire:click="openComplete">Complete walk</flux:button>
            @endif
        </div>

        {{-- Mobile actions --}}
        <div class="flex shrink-0 items-center gap-2 sm:hidden">
            @if ($this->canUpdate() || $canReopen)
                <flux:dropdown position="bottom" align="end">
                    <flux:button variant="ghost" icon="ellipsis-vertical" aria-label="More actions" />

                    <flux:menu>
                        <flux:menu.item :href="route('walks.report', $walk)" icon="document-chart-bar" wire:navigate>{{ $reportLabel }}</flux:menu.item>

                        @if ($this->canUpdate())
                            <flux:menu.item href="{{ route('walks.edit', $walk) }}" icon="pencil" wire:navigate>Edit</flux:menu.item>
                        @endif

                        @if ($canReopen)
                            <flux:menu.item icon="arrow-uturn-left" wire:click="reopenWalk">Reopen</flux:menu.item>
                        @endif
                    </flux:menu>
                </flux:dropdown>
            @else
                <flux:button :href="route('walks.report', $walk)" icon="document-chart-bar" :variant="$reportVariant" wire:navigate>{{ $reportLabel }}</flux:button>
            @endif

            @if ($canComplete)
                <flux:button variant="primary" icon="check-circle" wire:click="openComplete">Complete walk</flux:button>
            @endif
        </div>
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
                            <img src="{{ $finding->thumbnail_url }}" alt="Finding photo" loading="lazy" wire:click="openLightbox({{ $finding->id }})" class="aspect-[4/3] w-full cursor-zoom-in rounded-lg object-cover" />
                        @else
                            <div wire:click="openLightbox({{ $finding->id }})" class="flex aspect-[4/3] w-full cursor-zoom-in items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon name="photo" class="size-8 text-zinc-300 dark:text-zinc-600" />
                            </div>
                        @endif

                        <span class="absolute left-5 top-5 rounded-md bg-zinc-900/70 px-2 py-0.5 text-xs font-medium text-white">#{{ $finding->position }}</span>
                    </div>

                    <div class="flex flex-1 flex-col gap-2 p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:badge :color="$finding->category->color()" :icon="$finding->category->icon()">{{ $finding->category->label() }}</flux:badge>
                                <flux:badge :color="$finding->severity->color()">{{ $finding->severity->label() }}</flux:badge>
                            </div>

                            <div class="flex shrink-0 items-center gap-1">
                                @can('update', $finding)
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editFinding({{ $finding->id }})" aria-label="Edit finding" />
                                @endcan

                                @can('delete', $finding)
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $finding->id }})" aria-label="Delete finding" />
                                @endcan
                            </div>
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

    {{-- Edit finding modal --}}
    <flux:modal name="edit-finding" variant="flyout" class="md:w-[42rem]">
        <form wire:submit="saveFinding" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">Edit finding</flux:heading>
                <flux:subheading>Update the details of this finding.</flux:subheading>
            </div>

            <div class="flex flex-col gap-2">
                @if ($editing?->photo_url)
                    <img src="{{ $editing->photo_url }}" alt="Finding photo" class="max-h-64 w-full rounded-lg object-cover" />
                @else
                    <div class="flex max-h-64 w-full items-center justify-center rounded-lg bg-zinc-100 py-12 dark:bg-zinc-800">
                        <flux:icon name="photo" class="size-8 text-zinc-300 dark:text-zinc-600" />
                    </div>
                @endif

                <label class="inline-flex cursor-pointer items-center gap-1.5 text-sm font-medium text-teal-600 dark:text-teal-400">
                    <flux:icon name="arrow-path" variant="mini" class="size-4" />
                    Replace photo
                    <input type="file" class="sr-only" accept="image/jpeg,image/png,image/webp" wire:model="replacement">
                </label>

                @error('replacement')
                    <p class="text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <flux:field>
                <flux:label>Category</flux:label>
                <flux:radio.group wire:model="category" variant="cards" class="grid grid-cols-2 gap-2 md:grid-cols-3">
                    @foreach (FindingCategory::cases() as $case)
                        <flux:radio :value="$case->value" :label="$case->label()" :description="$case->description()" :icon="$case->icon()" />
                    @endforeach
                </flux:radio.group>
            </flux:field>

            <flux:field>
                <flux:label>Severity</flux:label>
                <flux:radio.group wire:model="severity" variant="segmented">
                    @foreach (Severity::cases() as $case)
                        <flux:radio :value="$case->value" :label="$case->label()" />
                    @endforeach
                </flux:radio.group>
            </flux:field>

            <flux:field>
                <flux:label>Observation</flux:label>
                <flux:textarea wire:model="observation" rows="3" placeholder="What did you see?"></flux:textarea>
                <flux:error name="observation" />
            </flux:field>

            <flux:field>
                <flux:label>Action required</flux:label>
                <flux:textarea wire:model="actionRequired" rows="2" placeholder="What should be done?"></flux:textarea>
                <flux:error name="actionRequired" />
            </flux:field>

            <div class="grid grid-cols-2 gap-3">
                <flux:field>
                    <flux:label>Action owner</flux:label>
                    <flux:input wire:model="actionOwner" icon="user" placeholder="Who owns it?" />
                    <flux:error name="actionOwner" />
                </flux:field>

                <flux:field>
                    <flux:label>Due date</flux:label>
                    <flux:input type="date" wire:model="dueOn" />
                    <flux:error name="dueOn" />
                </flux:field>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button variant="primary" type="submit">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete finding modal --}}
    <flux:modal name="delete-finding" class="md:max-w-md">
        <div class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">Delete this finding?</flux:heading>
                <flux:subheading>The photo will be removed.</flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="deleteFinding">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Lightbox modal --}}
    <flux:modal name="lightbox" class="max-w-4xl">
        @if ($lightboxFinding)
            <div class="flex flex-col gap-4">
                @if ($lightboxFinding->photo_url)
                    <img src="{{ $lightboxFinding->photo_url }}" alt="Finding photo" class="w-full rounded-lg" />
                @else
                    <div class="flex aspect-video w-full items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                        <flux:icon name="photo" class="size-10 text-zinc-300 dark:text-zinc-600" />
                    </div>
                @endif

                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge :color="$lightboxFinding->category->color()" :icon="$lightboxFinding->category->icon()">{{ $lightboxFinding->category->label() }}</flux:badge>
                    <flux:badge :color="$lightboxFinding->severity->color()">{{ $lightboxFinding->severity->label() }}</flux:badge>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">#{{ $lightboxFinding->position }}</span>
                </div>

                @if ($lightboxFinding->observation)
                    <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $lightboxFinding->observation }}</p>
                @endif
            </div>
        @endif
    </flux:modal>

    {{-- Complete walk modal --}}
    <flux:modal name="complete-walk" class="md:max-w-lg">
        <form wire:submit="completeWalk" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">Complete walk</flux:heading>
                <flux:subheading>Wrap up this gemba walk with your overall takeaways.</flux:subheading>
            </div>

            @if ($undescribedFindings->isNotEmpty())
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <div class="flex flex-col gap-2">
                        <p class="text-sm font-medium">These findings need a description:</p>

                        @foreach ($undescribedFindings as $finding)
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm">#{{ $finding->position }} needs a description</span>
                                <flux:button size="sm" wire:click="fixFinding({{ $finding->id }})">Fix</flux:button>
                            </div>
                        @endforeach
                    </div>
                </flux:callout>
            @endif

            <flux:field>
                <flux:label>Overall takeaways</flux:label>
                <flux:textarea wire:model="completionSummary" rows="4" placeholder="What did you learn?"></flux:textarea>
                <flux:error name="completionSummary" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button variant="primary" type="submit">Complete walk</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
