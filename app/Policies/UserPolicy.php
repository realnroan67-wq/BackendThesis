<?php

namespace App\Policies;

use App\Models\User;

/**
 * CURRENT: A user may view only their own profile; no administrator bypass.
 * FUTURE: Administrative access and role changes need separately authorized flows.
 * Property and inquiry policies must use their persisted ownership/grant records.
 */
class UserPolicy
{
    public function view(User $actor, User $subject): bool
    {
        return $actor->exists && $subject->exists && $actor->is($subject);
    }
}
