<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ChecklistAnswerPhoto extends Model
{
    /**
     * Most photos a single checklist answer may hold.
     */
    public const MAX_PER_ANSWER = 10;

    /**
     * The answer this photo is evidence for.
     */
    public function answer(): BelongsTo
    {
        return $this->belongsTo(ChecklistAnswer::class, 'checklist_answer_id');
    }

    /**
     * Public URL of the full-size photo.
     */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn () => Storage::disk('public')->url($this->photo_path));
    }

    /**
     * Public URL of the thumbnail.
     */
    protected function thumbnailUrl(): Attribute
    {
        return Attribute::get(fn () => Storage::disk('public')->url($this->thumbnail_path));
    }

    /**
     * Remove the image files along with the row.
     */
    protected static function booted(): void
    {
        static::deleted(function (ChecklistAnswerPhoto $photo) {
            Storage::disk('public')->delete(array_filter([$photo->photo_path, $photo->thumbnail_path]));
        });
    }
}
