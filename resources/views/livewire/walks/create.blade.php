<?php

use App\Enums\WalkStatus;
use App\Livewire\Concerns\WithWalkForm;
use App\Models\GembaWalk;
use Flux\Flux;
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
     * Persist a new draft walk owned by the authenticated user.
     */
    public function save(): void
    {
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

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">New walk</flux:heading>
        <flux:subheading size="lg">Start a gemba walk and capture what you find.</flux:subheading>
    </div>

    <flux:card class="max-w-2xl">
        <form wire:submit="save" class="space-y-6">
            @include('livewire.walks.partials.form')

            <div class="flex items-center justify-end gap-2">
                <flux:button href="{{ route('walks.index') }}" variant="ghost" wire:navigate>Cancel</flux:button>
                <flux:button variant="primary" type="submit">Start walk</flux:button>
            </div>
        </form>
    </flux:card>
</div>
