<?php

use App\Livewire\Concerns\WithWalkForm;
use App\Models\GembaWalk;
use Flux\Flux;
use Livewire\Volt\Component;

new class extends Component {
    use WithWalkForm;

    public GembaWalk $walk;

    /**
     * Authorize the owner to edit this draft walk and prefill the form.
     */
    public function mount(GembaWalk $walk): void
    {
        $this->authorize('update', $walk);

        $this->walk = $walk;
        $this->title = $walk->title;
        $this->area = $walk->area;
        $this->walked_on = $walk->walked_on->format('Y-m-d');
        $this->summary = $walk->summary ?? '';
    }

    /**
     * Persist the updated walk details.
     */
    public function save(): void
    {
        $this->authorize('update', $this->walk);

        $validated = $this->validate();

        $this->walk->fill($validated)->save();

        Flux::toast(variant: 'success', text: 'Walk updated');

        $this->redirectRoute('walks.show', ['walk' => $this->walk]);
    }

    /**
     * Delete the walk (and, through model events, its findings and files).
     */
    public function delete(): void
    {
        $this->authorize('delete', $this->walk);

        $this->walk->delete();

        $this->modal('confirm-delete')->close();

        Flux::toast(variant: 'success', text: 'Walk deleted');

        $this->redirectRoute('walks.index');
    }

    /**
     * Whether the current user may delete this walk.
     */
    public function canDelete(): bool
    {
        return auth()->user()->can('delete', $this->walk);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Edit walk</flux:heading>
        <flux:subheading size="lg">Update the details of this gemba walk.</flux:subheading>
    </div>

    <flux:card class="max-w-2xl">
        <form wire:submit="save" class="space-y-6">
            @include('livewire.walks.partials.form')

            <div class="flex items-center justify-end gap-2">
                <flux:button href="{{ route('walks.show', $walk) }}" variant="ghost" wire:navigate>Cancel</flux:button>
                <flux:button variant="primary" type="submit">Save changes</flux:button>
            </div>
        </form>
    </flux:card>

    @if ($this->canDelete())
        <flux:card class="max-w-2xl">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="text-sm font-semibold text-zinc-800 dark:text-white">Danger zone</div>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Deleting this walk also deletes its photos and findings.</p>
                </div>

                <flux:modal.trigger name="confirm-delete">
                    <flux:button variant="danger" icon="trash">Delete walk</flux:button>
                </flux:modal.trigger>
            </div>
        </flux:card>

        <flux:modal name="confirm-delete" class="md:max-w-md">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Delete this walk?</flux:heading>
                    <flux:subheading>Photos and findings attached to this walk will be permanently deleted too. This cannot be undone.</flux:subheading>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>

                    <flux:button variant="danger" wire:click="delete">Delete walk</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
