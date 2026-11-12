<?php

use App\Enums\ActionStatus;
use App\Enums\FindingCategory;
use App\Enums\Severity;
use App\Enums\YesNo;
use App\Models\ChecklistAnswer;
use App\Models\ChecklistCategory;
use App\Models\ChecklistItem;
use App\Models\Finding;
use App\Models\GembaWalk;
use App\Services\FindingPhotoProcessor;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    #[Locked]
    public GembaWalk $walk;

    public ?int $openCategoryId = null;

    /**
     * Notes for "No" answers, keyed by checklist item id.
     *
     * @var array<int, string>
     */
    public array $notes = [];

    /**
     * Photo uploads for "No" answers, keyed by checklist item id.
     *
     * @var array<int, mixed>
     */
    public array $photos = [];

    public function mount(GembaWalk $walk): void
    {
        $this->authorize('view', $walk);

        $this->walk = $walk;
        $this->notes = $walk->checklistAnswers()->pluck('note', 'checklist_item_id')
            ->filter(fn ($note, $itemId) => $itemId !== null)
            ->map(fn ($note) => (string) $note)
            ->all();
    }

    /**
     * Re-render when the parent page deletes a finding that an answer may link to.
     */
    #[On('finding-deleted')]
    public function refreshAfterFindingDeleted(): void
    {
        //
    }

    /**
     * Whether the viewer may answer checks on this walk (owner, while a draft).
     */
    public function canAnswer(): bool
    {
        return auth()->user()->can('update', $this->walk);
    }

    /**
     * This walk's answers, keyed by checklist item id.
     */
    public function answers(): Collection
    {
        return $this->walk->checklistAnswers()->with('finding')->get()->keyBy('checklist_item_id');
    }

    /**
     * Active categories with their active checks, for answering.
     */
    public function categories(): Collection
    {
        return ChecklistCategory::query()
            ->active()
            ->ordered()
            ->with('activeItems')
            ->get()
            ->filter(fn (ChecklistCategory $category) => $category->activeItems->isNotEmpty())
            ->values();
    }

    public function toggleCategory(int $id): void
    {
        $this->openCategoryId = $this->openCategoryId === $id ? null : $id;
    }

    /**
     * Record Yes or No for a check. Tapping the selected answer again clears it.
     */
    public function answer(int $itemId, string $value): void
    {
        $this->authorize('update', $this->walk);

        $answer = YesNo::tryFrom($value);
        abort_if($answer === null, 422);

        $item = $this->answerableItem($itemId);

        $existing = $this->walk->checklistAnswers()->where('checklist_item_id', $item->id)->first();

        if ($existing && $existing->answer === $answer) {
            $existing->delete();
            unset($this->notes[$item->id]);

            return;
        }

        $record = $existing ?? new ChecklistAnswer;

        // Copy the names so this walk's record survives later edits by an admin.
        $record->gemba_walk_id = $this->walk->id;
        $record->checklist_item_id = $item->id;
        $record->checklist_category_id = $item->checklist_category_id;
        $record->category_name = $item->category->name;
        $record->question = $item->question;
        $record->answer = $answer;
        $record->save();
    }

    /**
     * Save the note typed under a "No" answer.
     */
    public function updatedNotes(mixed $value, string|int $itemId): void
    {
        $this->authorize('update', $this->walk);

        $this->validate(["notes.{$itemId}" => ['nullable', 'string', 'max:1000']], attributes: ["notes.{$itemId}" => 'note']);

        $answer = $this->walk->checklistAnswers()->where('checklist_item_id', (int) $itemId)->first();

        if (! $answer) {
            return;
        }

        $note = trim((string) $value);
        $answer->update(['note' => $note === '' ? null : $note]);

        // Keep the linked finding's description in step while it still uses our wording.
        if ($answer->finding && str_starts_with((string) $answer->finding->observation, $this->observationPrefix($answer))) {
            $answer->finding->update(['observation' => $this->observationFor($answer)]);
        }
    }

    /**
     * Turn a photo attached to a "No" answer into a finding (or replace its photo).
     */
    public function updatedPhotos(mixed $file, string|int $itemId): void
    {
        $itemId = (int) $itemId;

        $this->authorize('create', [Finding::class, $this->walk]);

        $this->validate(
            ["photos.{$itemId}" => FindingPhotoProcessor::rules()['photo']],
            ["photos.{$itemId}.mimes" => FindingPhotoProcessor::messages()['photo.mimes']],
            ["photos.{$itemId}" => 'photo'],
        );

        $answer = $this->walk->checklistAnswers()->where('checklist_item_id', $itemId)->firstOrFail();
        abort_unless($answer->isNo(), 422);

        $processor = app(FindingPhotoProcessor::class);

        if ($answer->finding) {
            $processor->replace($answer->finding, $this->photos[$itemId]);
            $finding = $answer->finding;
        } else {
            $paths = $processor->store($this->photos[$itemId], $this->walk);

            $finding = new Finding;
            $finding->gemba_walk_id = $this->walk->id;
            $finding->photo_path = $paths['photo_path'];
            $finding->thumbnail_path = $paths['thumbnail_path'];
            $finding->category = FindingCategory::Other;
            $finding->severity = Severity::Medium;
            $finding->action_status = ActionStatus::Open;
            $finding->observation = $this->observationFor($answer);
            $finding->position = (int) $this->walk->findings()->max('position') + 1;
            $finding->save();

            $answer->finding()->associate($finding)->save();
        }

        unset($this->photos[$itemId]);

        Flux::toast(variant: 'success', text: 'Photo added to this check');

        // The walk page listens for this, refreshes its findings and opens the editor.
        $this->dispatch('finding-added', id: $finding->id);
    }

    /**
     * A check that can still be answered: active, in an active category.
     */
    private function answerableItem(int $itemId): ChecklistItem
    {
        return ChecklistItem::query()
            ->with('category')
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->findOrFail($itemId);
    }

    private function observationPrefix(ChecklistAnswer $answer): string
    {
        return "{$answer->category_name}: {$answer->question} - No";
    }

    private function observationFor(ChecklistAnswer $answer): string
    {
        return $this->observationPrefix($answer).($answer->note ? ". {$answer->note}" : '');
    }
}; ?>

@php
    $canAnswer = $this->canAnswer();
    $answers = $this->answers();

    // Owners of a draft answer the live checklist; everyone else sees what was recorded.
    $sections = $canAnswer
        ? $this->categories()->map(fn ($category) => [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'rows' => $category->activeItems->map(fn ($item) => ['itemId' => $item->id, 'question' => $item->question, 'answer' => $answers->get($item->id)]),
        ])
        : $answers->values()->groupBy('category_name')->map(fn ($group, $name) => [
            'id' => 'answered-'.md5($name),
            'name' => $name,
            'description' => null,
            'rows' => $group->map(fn ($answer) => ['itemId' => $answer->checklist_item_id, 'question' => $answer->question, 'answer' => $answer]),
        ])->values();

    $total = $sections->sum(fn ($section) => $section['rows']->count());
    $answered = $sections->sum(fn ($section) => $section['rows']->filter(fn ($row) => $row['answer'])->count());
    $noCount = $sections->sum(fn ($section) => $section['rows']->filter(fn ($row) => $row['answer']?->isNo())->count());
@endphp

<div>
    @if ($sections->isNotEmpty())
        <section class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 p-4 dark:border-zinc-800">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 items-center justify-center rounded-lg bg-teal-50 text-teal-700 dark:bg-teal-500/10 dark:text-teal-300">
                        <flux:icon name="clipboard-document-check" class="size-5" />
                    </div>
                    <div>
                        <flux:heading size="lg">Checklist</flux:heading>
                        <flux:text class="text-sm">{{ $answered }} of {{ $total }} checked @if ($noCount) &middot; <span class="font-medium text-red-600 dark:text-red-400">{{ $noCount }} No</span> @endif</flux:text>
                    </div>
                </div>

                <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-100 sm:w-48 dark:bg-zinc-800">
                    <div class="h-full rounded-full bg-teal-500 transition-all" style="width: {{ $total ? round($answered / $total * 100) : 0 }}%"></div>
                </div>
            </div>

            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($sections as $section)
                    @php
                        $sectionAnswered = $section['rows']->filter(fn ($row) => $row['answer'])->count();
                        $sectionNo = $section['rows']->filter(fn ($row) => $row['answer']?->isNo())->count();
                        $isOpen = $openCategoryId === $section['id'] || ! $canAnswer;
                        $isDone = $sectionAnswered === $section['rows']->count();
                    @endphp

                    <div wire:key="section-{{ $section['id'] }}">
                        @if ($canAnswer)
                            <button type="button" wire:click="toggleCategory({{ $section['id'] }})" class="flex min-h-14 w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <div class="min-w-0 flex-1">
                                    <div class="font-medium text-zinc-900 dark:text-white">{{ $section['name'] }}</div>
                                    @if ($section['description'])
                                        <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $section['description'] }}</div>
                                    @endif
                                </div>
                                @if ($sectionNo)
                                    <flux:badge size="sm" color="red">{{ $sectionNo }} No</flux:badge>
                                @endif
                                <flux:badge size="sm" :color="$isDone ? 'green' : 'zinc'" :icon="$isDone ? 'check' : null">{{ $sectionAnswered }}/{{ $section['rows']->count() }}</flux:badge>
                                <flux:icon :name="$isOpen ? 'chevron-up' : 'chevron-down'" class="size-4 shrink-0 text-zinc-400" />
                            </button>
                        @else
                            <div class="flex items-center gap-3 px-4 pt-3">
                                <div class="flex-1 font-medium text-zinc-900 dark:text-white">{{ $section['name'] }}</div>
                                @if ($sectionNo)
                                    <flux:badge size="sm" color="red">{{ $sectionNo }} No</flux:badge>
                                @endif
                            </div>
                        @endif

                        @if ($isOpen)
                            <ul class="space-y-1 px-4 pb-3">
                                @foreach ($section['rows'] as $row)
                                    @php($rowAnswer = $row['answer'])
                                    <li wire:key="row-{{ $section['id'] }}-{{ $row['itemId'] ?? $loop->index }}" class="rounded-lg py-2">
                                        <div class="flex items-center gap-3">
                                            <span class="min-w-0 flex-1 text-sm text-zinc-800 dark:text-zinc-100">{{ $row['question'] }}</span>

                                            @if ($canAnswer)
                                                <div class="flex shrink-0 gap-1.5">
                                                    @foreach ([YesNo::Yes, YesNo::No] as $option)
                                                        @php($selected = $rowAnswer?->answer === $option)
                                                        <button type="button"
                                                            wire:click="answer({{ $row['itemId'] }}, '{{ $option->value }}')"
                                                            aria-pressed="{{ $selected ? 'true' : 'false' }}"
                                                            @class([
                                                                'min-h-10 w-16 rounded-lg border text-sm font-medium transition',
                                                                'border-green-600 bg-green-600 text-white' => $selected && $option === YesNo::Yes,
                                                                'border-red-600 bg-red-600 text-white' => $selected && $option === YesNo::No,
                                                                'border-zinc-200 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800' => ! $selected,
                                                            ])
                                                        >{{ $option->label() }}</button>
                                                    @endforeach
                                                </div>
                                            @elseif ($rowAnswer)
                                                <flux:badge size="sm" :color="$rowAnswer->answer->color()">{{ $rowAnswer->answer->label() }}</flux:badge>
                                            @endif
                                        </div>

                                        {{-- Extra detail for failed checks --}}
                                        @if ($rowAnswer?->isNo())
                                            <div class="mt-2 flex items-start gap-3 rounded-lg bg-red-50 p-2.5 dark:bg-red-500/10">
                                                @if ($rowAnswer->finding?->thumbnail_url)
                                                    <img src="{{ $rowAnswer->finding->thumbnail_url }}" alt="" class="size-12 shrink-0 rounded-md object-cover">
                                                @endif

                                                <div class="min-w-0 flex-1 space-y-2">
                                                    @if ($canAnswer)
                                                        <flux:input size="sm" wire:model.blur="notes.{{ $row['itemId'] }}" placeholder="What's wrong? (optional)" />
                                                        @error("notes.{$row['itemId']}") <p class="text-xs text-red-600">{{ $message }}</p> @enderror

                                                        <label class="inline-flex cursor-pointer items-center gap-1.5 text-sm font-medium text-red-700 hover:text-red-800 dark:text-red-300">
                                                            <flux:icon name="camera" class="size-4" />
                                                            <span wire:loading.remove wire:target="photos.{{ $row['itemId'] }}">{{ $rowAnswer->finding ? 'Replace photo' : 'Add photo' }}</span>
                                                            <span wire:loading wire:target="photos.{{ $row['itemId'] }}">Uploading&hellip;</span>
                                                            <input type="file" class="sr-only" accept="image/*" capture="environment" wire:model="photos.{{ $row['itemId'] }}">
                                                        </label>
                                                        @error("photos.{$row['itemId']}") <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                                    @elseif ($rowAnswer->note)
                                                        <p class="text-sm text-red-800 dark:text-red-200">{{ $rowAnswer->note }}</p>
                                                    @endif
                                                </div>
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @elseif ($canAnswer)
        <div class="flex items-center gap-3 rounded-xl border border-dashed border-zinc-300 p-4 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            <flux:icon name="clipboard-document-check" class="size-5 shrink-0" />
            <span class="flex-1">No checklists are set up yet.</span>
            @can('checklists.manage')
                <flux:button size="sm" :href="route('admin.checklists')" wire:navigate>Set up checklists</flux:button>
            @endcan
        </div>
    @endif
</div>
