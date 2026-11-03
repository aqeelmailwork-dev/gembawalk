<?php

namespace App\Models;

use App\Enums\ActionStatus;
use App\Enums\FindingCategory;
use App\Enums\Severity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Finding extends Model
{
    /** @use HasFactory<\Database\Factories\FindingFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * gemba_walk_id, photo_path and thumbnail_path are intentionally excluded:
     * they are set explicitly, never through mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'category',
        'severity',
        'observation',
        'action_required',
        'action_owner',
        'due_on',
        'action_status',
        'position',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => FindingCategory::class,
            'severity' => Severity::class,
            'action_status' => ActionStatus::class,
            'due_on' => 'date',
        ];
    }

    /**
     * The walk this finding belongs to.
     */
    public function walk(): BelongsTo
    {
        return $this->belongsTo(GembaWalk::class, 'gemba_walk_id');
    }

    /**
     * Public URL for the stored photo, or null when there is none.
     */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
        );
    }

    /**
     * Public URL for the stored thumbnail, or null when there is none.
     */
    protected function thumbnailUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->thumbnail_path ? Storage::disk('public')->url($this->thumbnail_path) : null,
        );
    }

    /**
     * Whether the open action's due date has already passed.
     */
    public function isOverdue(): bool
    {
        return $this->action_status === ActionStatus::Open
            && $this->due_on !== null
            && $this->due_on->isPast();
    }

    /**
     * Remove the associated files from the public disk when deleted.
     */
    protected static function booted(): void
    {
        static::deleted(function (Finding $finding) {
            $disk = Storage::disk('public');

            if ($finding->photo_path) {
                $disk->delete($finding->photo_path);
            }

            if ($finding->thumbnail_path) {
                $disk->delete($finding->thumbnail_path);
            }
        });
    }
}
