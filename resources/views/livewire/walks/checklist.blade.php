<?php

use App\Enums\YesNo;
use App\Models\ChecklistAnswer;
use App\Models\ChecklistAnswerPhoto;
use App\Models\ChecklistCategory;
use App\Models\ChecklistItem;
use App\Models\GembaWalk;
use App\Services\FindingPhotoProcessor;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
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
     * Pending photo uploads, keyed by checklist item id (each a list of files).
     *
     * @var array<int, array<int, mixed>>
     */
    public array $photos = [];

    public function mount(GembaWalk $walk): void
    {
        $this->authorize('view', $walk);

        $this->walk = $walk;

        // A walk started from a category opens straight onto its checks.
        $this->openCategoryId = $walk->checklist_category_id;

        $this->notes = $walk->checklistAnswers()->pluck('note', 'checklist_item_id')
            ->filter(fn ($note, $itemId) => $itemId !== null)
            ->map(fn ($note) => (string) $note)
            ->all();
    }

    /**
     * Whether the viewer may answer checks on this walk (owner, while a draft).
     */
    public function canAnswer(): bool
    {
        return auth()->user()->can('update', $this->walk);
    }

    /**
     * This walk's answers with their photos, keyed by checklist item id.
     */
    public function answers(): Collection
    {
        return $this->walk->checklistAnswers()->with('photos')->get()->keyBy('checklist_item_id');
    }

    /**
     * Active categories with their active checks, for answering. A walk started
     * for one category only offers that category.
     */
    public function categories(): Collection
    {
        return ChecklistCategory::query()
            ->active()
            ->when($this->walk->checklist_category_id, fn ($query, $categoryId) => $query->whereKey($categoryId))
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
     * Record Yes or No for a check. Tapping the selected answer again clears it
     * (and deletes its photos; the button asks first when there are any).
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
            unset($this->notes[$item->id], $this->photos[$item->id]);

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

        $note = trim((string) $value);

        $this->walk->checklistAnswers()
            ->where('checklist_item_id', (int) $itemId)
            ->update(['note' => $note === '' ? null : $note]);
    }

    /**
     * Store photos picked for an answered check (camera or gallery, several at once).
     */
    public function updatedPhotos(mixed $value, string|int $key): void
    {
        // Livewire reports either "5" (the whole list) or "5.0" (one file).
        $itemId = (int) explode('.', (string) $key)[0];

        $this->authorize('update', $this->walk);

        $answer = $this->walk->checklistAnswers()->where('checklist_item_id', $itemId)->firstOrFail();

        $files = array_values(array_filter((array) ($this->photos[$itemId] ?? [])));

        $this->validate(
            [
                "photos.{$itemId}" => ['required', 'array'],
                "photos.{$itemId}.*" => FindingPhotoProcessor::rules()['photo'],
            ],
            ["photos.{$itemId}.*.mimes" => FindingPhotoProcessor::messages()['photo.mimes']],
            ["photos.{$itemId}" => 'photos', "photos.{$itemId}.*" => 'photo'],
        );

        $room = ChecklistAnswerPhoto::MAX_PER_ANSWER - $answer->photos()->count();

        if (count($files) > $room) {
            unset($this->photos[$itemId]);
            $this->addError("photos.{$itemId}", $room > 0
                ? "You can add {$room} more ".Str::plural('photo', $room).' to this check (up to '.ChecklistAnswerPhoto::MAX_PER_ANSWER.').'
                : 'This check already has the maximum of '.ChecklistAnswerPhoto::MAX_PER_ANSWER.' photos.');

            return;
        }

        $processor = app(FindingPhotoProcessor::class);
        $position = (int) $answer->photos()->max('position');

        foreach ($files as $file) {
            $paths = $processor->store($file, $this->walk);

            $photo = new ChecklistAnswerPhoto;
            $photo->checklist_answer_id = $answer->id;
            $photo->photo_path = $paths['photo_path'];
            $photo->thumbnail_path = $paths['thumbnail_path'];
            $photo->position = ++$position;
            $photo->save();
        }

        unset($this->photos[$itemId]);

        Flux::toast(variant: 'success', text: count($files).' '.Str::plural('photo', count($files)).' added');
    }

    /**
     * Remove one photo from a check on this walk.
     */
    public function removePhoto(int $photoId): void
    {
        $this->authorize('update', $this->walk);

        ChecklistAnswerPhoto::query()
            ->whereHas('answer', fn ($query) => $query->where('gemba_walk_id', $this->walk->id))
            ->findOrFail($photoId)
            ->delete();

        Flux::toast(variant: 'success', text: 'Photo removed');
    }

    /**
     * A check that can still be answered: active, in an active category, and in
     * the walk's own category when it was started for one.
     */
    private function answerableItem(int $itemId): ChecklistItem
    {
        return ChecklistItem::query()
            ->with('category')
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->when($this->walk->checklist_category_id, fn ($query, $categoryId) => $query->where('checklist_category_id', $categoryId))
            ->findOrFail($itemId);
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
                                    @php
                                        $rowAnswer = $row['answer'];
                                        $itemId = $row['itemId'];
                                        $rowPhotos = $rowAnswer?->photos ?? collect();
                                        $canAddMore = $rowPhotos->count() < \App\Models\ChecklistAnswerPhoto::MAX_PER_ANSWER;
                                    @endphp
                                    <li wire:key="row-{{ $section['id'] }}-{{ $itemId ?? $loop->index }}" class="py-2">
                                        <div class="flex items-center gap-3">
                                            <span class="min-w-0 flex-1 text-sm text-zinc-800 dark:text-zinc-100">{{ $row['question'] }}</span>

                                            @if ($canAnswer)
                                                <div class="flex shrink-0 gap-1.5">
                                                    @foreach ([YesNo::Yes, YesNo::No] as $option)
                                                        @php($selected = $rowAnswer?->answer === $option)
                                                        <button type="button"
                                                            wire:click="answer({{ $itemId }}, '{{ $option->value }}')"
                                                            @if ($selected && $rowPhotos->isNotEmpty()) wire:confirm="Clear this answer? Its {{ $rowPhotos->count() }} {{ Str::plural('photo', $rowPhotos->count()) }} will be deleted." @endif
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

                                        {{-- Note (failed checks) and photos (any answered check) --}}
                                        @if ($rowAnswer && ($rowAnswer->isNo() || $rowPhotos->isNotEmpty() || $canAnswer))
                                            <div @class([
                                                'mt-2 space-y-2',
                                                'rounded-lg bg-red-50 p-2.5 dark:bg-red-500/10' => $rowAnswer->isNo(),
                                            ])>
                                                @if ($rowAnswer->isNo())
                                                    @if ($canAnswer)
                                                        <flux:input size="sm" wire:model.blur="notes.{{ $itemId }}" placeholder="What's wrong? (optional)" />
                                                        @error("notes.{$itemId}") <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                                    @elseif ($rowAnswer->note)
                                                        <p class="text-sm text-red-800 dark:text-red-200">{{ $rowAnswer->note }}</p>
                                                    @endif
                                                @endif

                                                @if ($rowPhotos->isNotEmpty() || $canAnswer)
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        @foreach ($rowPhotos as $photo)
                                                            <div wire:key="photo-{{ $photo->id }}" class="relative">
                                                                <a href="{{ $photo->photo_url }}" target="_blank" rel="noopener">
                                                                    <img src="{{ $photo->thumbnail_url }}" alt="Photo {{ $loop->iteration }} for {{ $row['question'] }}" class="size-16 rounded-md object-cover ring-1 ring-black/5 dark:ring-white/10" loading="lazy">
                                                                </a>
                                                                @if ($canAnswer)
                                                                    <button type="button" wire:click="removePhoto({{ $photo->id }})" wire:confirm="Remove this photo?" aria-label="Remove photo"
                                                                        class="absolute -right-1.5 -top-1.5 flex size-5 items-center justify-center rounded-full bg-zinc-900/80 text-white shadow hover:bg-red-600">
                                                                        <flux:icon name="x-mark" class="size-3" />
                                                                    </button>
                                                                @endif
                                                            </div>
                                                        @endforeach

                                                        @if ($canAnswer && $canAddMore)
                                                            <label @class([
                                                                'flex size-16 cursor-pointer flex-col items-center justify-center gap-0.5 rounded-md border-2 border-dashed text-[11px] font-medium transition',
                                                                'border-red-300 text-red-700 hover:bg-red-100 dark:border-red-500/40 dark:text-red-300 dark:hover:bg-red-500/10' => $rowAnswer->isNo(),
                                                                'border-zinc-300 text-zinc-500 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-800' => ! $rowAnswer->isNo(),
                                                            ])>
                                                                <span wire:loading.remove wire:target="photos.{{ $itemId }}" class="flex flex-col items-center gap-0.5">
                                                                    <flux:icon name="camera" class="size-5" />
                                                                    {{ $rowPhotos->isEmpty() ? 'Add photos' : 'Add more' }}
                                                                </span>
                                                                <span wire:loading wire:target="photos.{{ $itemId }}">
                                                                    <flux:icon.loading class="size-5" />
                                                                </span>
                                                                {{-- No "capture" attribute: phones then offer camera or gallery, and allow several. --}}
                                                                <input type="file" class="sr-only" accept="image/*" multiple wire:model="photos.{{ $itemId }}">
                                                            </label>
                                                        @endif
                                                    </div>
                                                    @error("photos.{$itemId}") <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                                    @error("photos.{$itemId}.*") <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                                @endif
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
