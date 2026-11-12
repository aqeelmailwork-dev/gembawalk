<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistCategory extends Model
{
    /** @use HasFactory<\Database\Factories\ChecklistCategoryFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'position',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The yes/no checks in this category, in display order.
     */
    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Only the checks currently offered on walks.
     */
    public function activeItems(): HasMany
    {
        return $this->items()->where('is_active', true);
    }

    /**
     * Scope a query to categories currently offered on walks.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to display order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }
}
