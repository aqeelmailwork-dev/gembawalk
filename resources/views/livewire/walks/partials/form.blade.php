<div class="space-y-6">
    <flux:input
        wire:model="title"
        label="Title"
        placeholder="Line 3 morning walk"
        required
        maxlength="150"
    />

    <flux:input
        wire:model="area"
        label="Area"
        placeholder="Assembly Line 3"
        list="walk-areas"
        required
        maxlength="150"
    />

    <datalist id="walk-areas">
        @foreach ($this->areas() as $existingArea)
            <option value="{{ $existingArea }}"></option>
        @endforeach
    </datalist>

    <flux:input
        wire:model="walked_on"
        label="Walked on"
        type="date"
        required
        max="{{ now()->toDateString() }}"
    />

    <flux:textarea
        wire:model="summary"
        label="Summary / purpose"
        rows="4"
        maxlength="2000"
        placeholder="What are you focusing on today?"
    />
</div>
