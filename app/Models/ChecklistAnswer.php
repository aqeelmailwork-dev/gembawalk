<?php

namespace App\Models;

use App\Enums\YesNo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistAnswer extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'answer',
        'note',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answer' => YesNo::class,
        ];
    }

    /**
     * The walk this answer was given on.
     */
    public function walk(): BelongsTo
    {
        return $this->belongsTo(GembaWalk::class, 'gemba_walk_id');
    }

    /**
     * The category the check belonged to (null if an admin has since deleted it).
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ChecklistCategory::class, 'checklist_category_id');
    }

    /**
     * The check that was answered (null if an admin has since deleted it).
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }

    /**
     * The photo finding attached to a "No" answer, if any.
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    /**
     * Whether the check failed.
     */
    public function isNo(): bool
    {
        return $this->answer === YesNo::No;
    }
}
