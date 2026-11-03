<?php

namespace App\Livewire\Concerns;

use App\Models\GembaWalk;

/**
 * Shared state and validation for the create and edit walk forms.
 */
trait WithWalkForm
{
    public string $title = '';

    public string $area = '';

    public string $walked_on = '';

    public string $summary = '';

    /**
     * Validation rules shared by the create and edit walk forms.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'area' => ['required', 'string', 'max:150'],
            'walked_on' => ['required', 'date', 'before_or_equal:today'],
            'summary' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The current user's previously used areas, for the datalist suggestions.
     *
     * @return array<int, string>
     */
    public function areas(): array
    {
        return GembaWalk::query()
            ->where('user_id', auth()->id())
            ->distinct()
            ->orderBy('area')
            ->pluck('area')
            ->all();
    }
}
