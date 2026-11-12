<?php

namespace App\Models;

use App\Enums\YesNo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Evidence photos attached to this answer, in the order they were added.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(ChecklistAnswerPhoto::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Delete photos through Eloquent so their files are removed too (a database
     * cascade alone would leave the images on disk).
     */
    protected static function booted(): void
    {
        static::deleting(function (ChecklistAnswer $answer) {
            $answer->photos->each->delete();
        });
    }

    /**
     * Whether the check failed.
     */
    public function isNo(): bool
    {
        return $this->answer === YesNo::No;
    }
}
