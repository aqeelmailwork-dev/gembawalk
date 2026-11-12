<?php

use App\Enums\Permission;
use App\Models\ChecklistCategory;
use App\Models\ChecklistItem;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * Category form (shared by create and edit).
     */
    public ?int $editingCategoryId = null;
    public string $categoryName = '';
    public string $categoryDescription = '';

    /**
     * New-check inputs, keyed by category id.
     *
     * @var array<int, string>
     */
    public array $newQuestion = [];

    /**
     * Check edit form.
     */
    public ?int $editingItemId = null;
    public string $itemQuestion = '';

    public ?int $deletingCategoryId = null;

    /**
     * All categories with their checks, in display order.
     */
    public function categories(): Collection
    {
        return ChecklistCategory::query()->ordered()->with('items')->get();
    }

    // -- Categories -----------------------------------------------------------

    public function openCreateCategory(): void
    {
        $this->ensureCanManage();

        $this->reset('editingCategoryId', 'categoryName', 'categoryDescription');
        $this->resetValidation();
        $this->modal('category-form')->show();
    }

    public function openEditCategory(int $id): void
    {
        $this->ensureCanManage();

        $category = ChecklistCategory::findOrFail($id);

        $this->editingCategoryId = $category->id;
        $this->categoryName = $category->name;
        $this->categoryDescription = (string) $category->description;
        $this->resetValidation();
        $this->modal('category-form')->show();
    }

    public function saveCategory(): void
    {
        $this->ensureCanManage();

        $validated = $this->validate([
            'categoryName' => ['required', 'string', 'max:120'],
            'categoryDescription' => ['nullable', 'string', 'max:255'],
        ], attributes: [
            'categoryName' => 'name',
            'categoryDescription' => 'description',
        ]);

        $data = [
            'name' => trim($validated['categoryName']),
            'description' => trim((string) $validated['categoryDescription']) ?: null,
        ];

        if ($this->editingCategoryId) {
            ChecklistCategory::findOrFail($this->editingCategoryId)->update($data);
            Flux::toast(variant: 'success', text: 'Category updated');
        } else {
            ChecklistCategory::create($data + ['position' => (int) ChecklistCategory::max('position') + 1]);
            Flux::toast(variant: 'success', text: 'Category added - now add its checks');
        }

        $this->modal('category-form')->close();
        $this->reset('editingCategoryId', 'categoryName', 'categoryDescription');
    }

    public function toggleCategory(int $id): void
    {
        $this->ensureCanManage();

        $category = ChecklistCategory::findOrFail($id);
        $category->update(['is_active' => ! $category->is_active]);

        Flux::toast(variant: 'success', text: $category->is_active ? "{$category->name} is shown on walks" : "{$category->name} is hidden from walks");
    }

    public function moveCategory(int $id, int $direction): void
    {
        $this->ensureCanManage();

        $this->swapPositions(ChecklistCategory::query()->ordered()->get(), $id, $direction);
    }

    public function confirmDeleteCategory(int $id): void
    {
        $this->ensureCanManage();

        $this->deletingCategoryId = ChecklistCategory::findOrFail($id)->id;
        $this->modal('delete-category')->show();
    }

    public function deleteCategory(): void
    {
        $this->ensureCanManage();

        $category = ChecklistCategory::findOrFail($this->deletingCategoryId);
        $name = $category->name;

        // Checks are removed by the foreign key cascade; answers on past walks keep
        // their own copy of the category name and question.
        $category->delete();

        $this->deletingCategoryId = null;
        $this->modal('delete-category')->close();

        Flux::toast(variant: 'success', text: "{$name} deleted");
    }

    // -- Checks ---------------------------------------------------------------

    public function addItem(int $categoryId): void
    {
        $this->ensureCanManage();

        $category = ChecklistCategory::findOrFail($categoryId);

        $this->validate([
            "newQuestion.{$categoryId}" => ['required', 'string', 'max:200'],
        ], attributes: [
            "newQuestion.{$categoryId}" => 'check',
        ]);

        $category->items()->create([
            'question' => trim($this->newQuestion[$categoryId]),
            'position' => (int) $category->items()->max('position') + 1,
        ]);

        $this->newQuestion[$categoryId] = '';

        Flux::toast(variant: 'success', text: 'Check added');
    }

    public function openEditItem(int $id): void
    {
        $this->ensureCanManage();

        $item = ChecklistItem::findOrFail($id);

        $this->editingItemId = $item->id;
        $this->itemQuestion = $item->question;
        $this->resetValidation();
        $this->modal('item-form')->show();
    }

    public function saveItem(): void
    {
        $this->ensureCanManage();

        $this->validate([
            'itemQuestion' => ['required', 'string', 'max:200'],
        ], attributes: [
            'itemQuestion' => 'check',
        ]);

        ChecklistItem::findOrFail($this->editingItemId)->update(['question' => trim($this->itemQuestion)]);

        $this->modal('item-form')->close();
        $this->reset('editingItemId', 'itemQuestion');

        Flux::toast(variant: 'success', text: 'Check updated');
    }

    public function toggleItem(int $id): void
    {
        $this->ensureCanManage();

        $item = ChecklistItem::findOrFail($id);
        $item->update(['is_active' => ! $item->is_active]);

        Flux::toast(variant: 'success', text: $item->is_active ? 'Check shown on walks' : 'Check hidden from walks');
    }

    public function moveItem(int $id, int $direction): void
    {
        $this->ensureCanManage();

        $item = ChecklistItem::findOrFail($id);

        $this->swapPositions($item->category->items()->get(), $id, $direction);
    }

    public function deleteItem(int $id): void
    {
        $this->ensureCanManage();

        ChecklistItem::findOrFail($id)->delete();

        Flux::toast(variant: 'success', text: 'Check deleted');
    }

    // -- Internals ------------------------------------------------------------

    private function ensureCanManage(): void
    {
        abort_unless(auth()->user()->can(Permission::ChecklistsManage->value), 403);
    }

    /**
     * Move one record up (-1) or down (+1) within an ordered list, renumbering
     * the whole list so positions stay 1..n.
     */
    private function swapPositions(Collection $ordered, int $id, int $direction): void
    {
        $ids = $ordered->pluck('id')->values()->all();
        $from = array_search($id, $ids, true);

        abort_if($from === false, 404);

        $to = $from + ($direction < 0 ? -1 : 1);

        if ($to < 0 || $to >= count($ids)) {
            return;
        }

        [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];

        $byId = $ordered->keyBy('id');

        foreach ($ids as $index => $recordId) {
            $byId[$recordId]->update(['position' => $index + 1]);
        }
    }
}; ?>

@php
    $categories = $this->categories();
@endphp

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Checklists</flux:heading>
            <flux:subheading size="lg">Group yes/no checks into categories. On a walk, people open a category and answer each check.</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openCreateCategory">New category</flux:button>
    </div>

    @forelse ($categories as $category)
        <div wire:key="category-{{ $category->id }}" @class([
            'overflow-hidden rounded-xl border bg-white shadow-sm dark:bg-zinc-900',
            'border-zinc-200 dark:border-zinc-700' => $category->is_active,
            'border-dashed border-zinc-300 opacity-75 dark:border-zinc-600' => ! $category->is_active,
        ])>
            {{-- Category header --}}
            <div class="flex items-start gap-3 border-b border-zinc-100 p-4 dark:border-zinc-800">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-teal-50 text-teal-700 dark:bg-teal-500/10 dark:text-teal-300">
                    <flux:icon name="clipboard-document-check" class="size-5" />
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold text-zinc-900 dark:text-white">{{ $category->name }}</span>
                        <flux:badge size="sm">{{ $category->items->count() }} {{ Str::plural('check', $category->items->count()) }}</flux:badge>
                        @unless ($category->is_active)
                            <flux:badge size="sm" color="zinc" icon="eye-slash">Hidden</flux:badge>
                        @endunless
                    </div>
                    @if ($category->description)
                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $category->description }}</p>
                    @endif
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    <flux:button size="sm" variant="ghost" icon="chevron-up" wire:click="moveCategory({{ $category->id }}, -1)" :disabled="$loop->first" aria-label="Move up" />
                    <flux:button size="sm" variant="ghost" icon="chevron-down" wire:click="moveCategory({{ $category->id }}, 1)" :disabled="$loop->last" aria-label="Move down" />

                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" aria-label="Category actions" />
                        <flux:menu>
                            <flux:menu.item icon="pencil-square" wire:click="openEditCategory({{ $category->id }})">Edit</flux:menu.item>
                            <flux:menu.item :icon="$category->is_active ? 'eye-slash' : 'eye'" wire:click="toggleCategory({{ $category->id }})">
                                {{ $category->is_active ? 'Hide from walks' : 'Show on walks' }}
                            </flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDeleteCategory({{ $category->id }})">Delete</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </div>

            {{-- Checks --}}
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($category->items as $item)
                    <li wire:key="item-{{ $item->id }}" class="flex items-center gap-2 px-4 py-2.5">
                        <flux:icon name="check-circle" variant="outline" @class(['size-5 shrink-0', 'text-teal-600 dark:text-teal-400' => $item->is_active, 'text-zinc-300 dark:text-zinc-600' => ! $item->is_active]) />

                        <span @class(['min-w-0 flex-1 text-sm', 'text-zinc-800 dark:text-zinc-100' => $item->is_active, 'text-zinc-400 line-through dark:text-zinc-500' => ! $item->is_active])>
                            {{ $item->question }}
                        </span>

                        <div class="flex shrink-0 items-center">
                            <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="moveItem({{ $item->id }}, -1)" :disabled="$loop->first" aria-label="Move check up" />
                            <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="moveItem({{ $item->id }}, 1)" :disabled="$loop->last" aria-label="Move check down" />
                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="openEditItem({{ $item->id }})" aria-label="Edit check" />
                            <flux:button size="xs" variant="ghost" :icon="$item->is_active ? 'eye-slash' : 'eye'" wire:click="toggleItem({{ $item->id }})" :aria-label="$item->is_active ? 'Hide check' : 'Show check'" />
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteItem({{ $item->id }})" wire:confirm="Delete this check? Answers already given on past walks are kept." aria-label="Delete check" />
                        </div>
                    </li>
                @endforeach
            </ul>

            {{-- Add a check --}}
            <form wire:submit="addItem({{ $category->id }})" class="flex items-start gap-2 bg-zinc-50 p-3 dark:bg-zinc-800/50">
                <div class="min-w-0 flex-1">
                    <flux:input wire:model="newQuestion.{{ $category->id }}" placeholder="Add a yes/no check, e.g. Desk area clean?" size="sm" />
                    @error("newQuestion.{$category->id}")
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
                <flux:button type="submit" size="sm" icon="plus">Add</flux:button>
            </form>
        </div>
    @empty
        <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-300 px-6 py-12 text-center dark:border-zinc-700">
            <div class="flex size-12 items-center justify-center rounded-full bg-teal-50 text-teal-600 dark:bg-teal-500/10 dark:text-teal-300">
                <flux:icon name="clipboard-document-check" class="size-6" />
            </div>
            <flux:heading>No checklist categories yet</flux:heading>
            <flux:text>Create a category such as "Office", then add yes/no checks like "Desk area clean?".</flux:text>
            <flux:button variant="primary" icon="plus" wire:click="openCreateCategory">New category</flux:button>
        </div>
    @endforelse

    {{-- Category create/edit --}}
    <flux:modal name="category-form" class="md:w-[28rem]">
        <form wire:submit="saveCategory" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingCategoryId ? 'Edit category' : 'New category' }}</flux:heading>
                <flux:subheading>An area or topic people check on a walk.</flux:subheading>
            </div>

            <flux:input wire:model="categoryName" label="Name" placeholder="Office" required />
            <flux:input wire:model="categoryDescription" label="Description (optional)" placeholder="Shared office floor and meeting rooms" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Check edit --}}
    <flux:modal name="item-form" class="md:w-[28rem]">
        <form wire:submit="saveItem" class="space-y-6">
            <flux:heading size="lg">Edit check</flux:heading>

            <flux:input wire:model="itemQuestion" label="Yes/no check" required />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Category delete --}}
    <flux:modal name="delete-category" class="md:max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Delete this category?</flux:heading>
                <flux:subheading>Its checks are removed for future walks. Answers already given on past walks stay in their reports. To keep it but stop using it, hide it instead.</flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteCategory">Delete</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
