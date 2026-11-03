<?php

namespace App\Policies;

use App\Models\Finding;
use App\Models\GembaWalk;
use App\Models\User;

class FindingPolicy
{
    /**
     * Findings may only be created on a draft walk owned by the user.
     */
    public function create(User $user, GembaWalk $walk): bool
    {
        return $this->canEditWalk($user, $walk);
    }

    /**
     * Findings may only be updated while their walk is an owned draft.
     */
    public function update(User $user, Finding $finding): bool
    {
        return $this->canEditWalk($user, $finding->walk);
    }

    /**
     * Findings may only be deleted while their walk is an owned draft.
     */
    public function delete(User $user, Finding $finding): bool
    {
        return $this->canEditWalk($user, $finding->walk);
    }

    /**
     * Whether the user may edit the walk the finding belongs to.
     */
    private function canEditWalk(User $user, GembaWalk $walk): bool
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
