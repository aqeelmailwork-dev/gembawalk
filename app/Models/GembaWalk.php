<?php

namespace App\Models;

use App\Enums\ActionStatus;
use App\Enums\FindingCategory;
use App\Enums\Severity;
use App\Enums\WalkStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GembaWalk extends Model
{
    /** @use HasFactory<\Database\Factories\GembaWalkFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'area',
        'walked_on',
        'summary',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'walked_on' => 'date',
            'completed_at' => 'datetime',
            'status' => WalkStatus::class,
        ];
    }

    /**
     * The user who performed the walk.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The findings recorded during the walk, in display order.
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The yes/no checklist answers given during the walk.
     */
    public function checklistAnswers(): HasMany
    {
        return $this->hasMany(ChecklistAnswer::class);
    }

    /**
     * Whether the walk is still a draft.
     */
    public function isDraft(): bool
    {
        return $this->status === WalkStatus::Draft;
    }

    /**
     * Whether the walk has been completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === WalkStatus::Completed;
    }

    /**
     * Mark the walk as completed.
     */
    public function markCompleted(): bool
    {
        return $this->forceFill([
            'status' => WalkStatus::Completed,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Reopen a completed walk back to draft.
     */
    public function reopen(): bool
    {
        return $this->forceFill([
            'status' => WalkStatus::Draft,
            'completed_at' => null,
        ])->save();
    }

    /**
     * Delete findings through Eloquent before the row is removed, so each
     * finding's own file cleanup runs (a DB cascade would skip model events).
     */
    protected static function booted(): void
    {
        static::deleting(function (GembaWalk $walk) {
            $walk->findings->each->delete();
        });
    }

    /**
     * Scope walks to those visible to the given user.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->can('walks.view_all')
            ? $query
            : $query->where('user_id', $user->id);
    }

    /**
     * Count of findings grouped by severity, including zero counts for every case.
     *
     * @return array<string, int>
     */
    public function findingsBySeverity(): array
    {
        $counts = $this->findings->countBy(fn (Finding $finding) => $finding->severity->value);

        return collect(Severity::cases())
            ->mapWithKeys(fn (Severity $case) => [$case->value => $counts->get($case->value, 0)])
            ->all();
    }

    /**
     * Count of findings grouped by category, including zero counts for every case.
     *
     * @return array<string, int>
     */
    public function findingsByCategory(): array
    {
        $counts = $this->findings->countBy(fn (Finding $finding) => $finding->category->value);

        return collect(FindingCategory::cases())
            ->mapWithKeys(fn (FindingCategory $case) => [$case->value => $counts->get($case->value, 0)])
            ->all();
    }

    /**
     * Number of findings whose action is still open.
     */
    public function openActionsCount(): int
    {
        return $this->findings
            ->filter(fn (Finding $finding) => $finding->action_status === ActionStatus::Open)
            ->count();
    }
}
