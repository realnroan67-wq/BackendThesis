<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Property;
use App\Models\User;
use App\Support\AccountEligibility;

/**
 * Provider management only; no public-discovery decision or administrator bypass.
 * Future writes must authorize and lock/recheck ownership in their transaction.
 * Inquiry permissions must use their own participant records, never this policy.
 */
class PropertyPolicy
{
    public function viewAny(User $user): bool
    {
        return AccountEligibility::allows($user, UserRole::PROPERTY_PROVIDER);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Property $property): bool
    {
        return $property->exists && Property::query()->ownedBy($user)->whereKey($property->getKey())->exists();
    }

    public function update(User $user, Property $property): bool
    {
        return $this->view($user, $property);
    }

    /** Hard deletion and ownership transfer workflows have not been approved. */
    public function delete(User $user, Property $property): bool
    {
        return false;
    }

    public function transferOwnership(User $user, Property $property): bool
    {
        return false;
    }
}
