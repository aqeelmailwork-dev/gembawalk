<?php

namespace App\Policies;

use App\Models\GembaWalk;
use App\Models\User;

class GembaWalkPolicy
{
    /**
     * Any approved user may browse walks.
     */
    public function viewAny(User $user): bool
    {
        return $user->isApproved();
    }

    /**
     * Owners and users who can see all walks may view a walk.
     */
    public function view(User $user, GembaWalk $walk): bool
    {
        return $this->owns($user, $walk) || $user->can('walks.view_all');
    }

    /**
     * Any approved user may create a walk.
     */
    public function create(User $user): bool
    {
        return $user->isApproved();
    }

    /**
     * Only the owner may update a walk, and only while it is a draft.
     */
    public function update(User $user, GembaWalk $walk): bool
    {
        return $this->canEdit($user, $walk);
    }

    /**
     * The owner may complete a draft walk that has at least one finding.
     */
    public function complete(User $user, GembaWalk $walk): bool
    {
        return $this->canEdit($user, $walk) && $walk->findings()->exists();
    }

    /**
     * The owner may reopen a completed walk.
     */
    public function reopen(User $user, GembaWalk $walk): bool
    {
        return $this->owns($user, $walk) && $walk->isCompleted();
    }

    /**
     * Owners may delete their own walks; others need delete-any permission.
     */
    public function delete(User $user, GembaWalk $walk): bool
    {
        return $this->owns($user, $walk) || $user->can('walks.delete_any');
    }

    /**
     * Exporting requires both visibility and the reports.export permission.
     */
    public function exportReport(User $user, GembaWalk $walk): bool
    {
        return ($this->owns($user, $walk) || $user->can('walks.view_all'))
            && $user->can('reports.export');
    }

    /**
     * Whether the user may edit this draft walk.
     */
    private function canEdit(User $user, GembaWalk $walk): bool
    {
        return $this->owns($user, $walk) && $walk->isDraft();
    }

    /**
     * Whether the user owns the walk, comparing the keys as integers so a
     * numeric-string user_id returned by some MySQL drivers still matches.
     */
    private function owns(User $user, GembaWalk $walk): bool
    {
        return (int) $walk->user_id === (int) $user->getKey();
    }
}
